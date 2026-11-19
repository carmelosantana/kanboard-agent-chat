<?php

namespace Kanboard\Plugin\AgentChat\Model;

use ChatParts\Model\Message;
use ChatParts\Model\MessageStatus;
use ChatParts\Model\Participant;
use ChatParts\Model\Part\Part;
use ChatParts\Model\Part\TextPart;
use InvalidArgumentException;
use Kanboard\Core\Base;
use RuntimeException;

/**
 * Chat messages: a participant, a status and an ordered list of chatparts parts, stored as the
 * JSON of each part's toArray(); body_text is the derived plain text (text parts only).
 *
 * Senders: `human` and `agent` are Kanboard users (sender_id = user id); `bot` (participant
 * bot:project) and `system` have sender_id NULL. Columns SQLite does not enforce (VARCHAR
 * lengths, enums) are validated here; anything invalid is an InvalidArgumentException.
 */
class MessageModel extends Base
{
    public const TABLE = 'agentchat_messages';
    public const MAX_BODY = 8000;
    public const SENDER_KINDS = ['human', 'agent', 'bot', 'system'];
    public const VISIBILITIES = ['room', 'private'];
    private const OPTIONS = ['client_msg_id', 'visibility', 'private_to', 'task_id', 'status', 'metadata'];
    private const CLIENT_MSG_ID = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @var array<int, string> user id => display name, per instance */
    private array $names = [];

    /** Whitespace trimmed before counting: ASCII and Unicode spaces, zero-width space, BOM. */
    private const EDGE_SPACE = '/^[\p{Z}\s\x{200B}\x{FEFF}]+|[\p{Z}\s\x{200B}\x{FEFF}]+$/u';

    /**
     * Error text for a body that is not valid UTF-8, holds a NUL byte, or is outside
     * 1..8000 characters once Unicode whitespace is trimmed from both ends; null when it is fine.
     */
    public static function validateBody(string $body): ?string
    {
        if (! mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) {
            return t('This message contains characters that cannot be sent.');
        }
        $length = mb_strlen((string) preg_replace(self::EDGE_SPACE, '', $body), 'UTF-8');
        if ($length === 0) {
            return t('Write a message first.');
        }
        if ($length > self::MAX_BODY) {
            return t('Messages are limited to %d characters.', self::MAX_BODY);
        }
        return null;
    }

    /**
     * Stores a message and returns its id. Human and agent bodies must pass validateBody();
     * bot and system messages may start empty (a bot draft is filled by update()).
     * A client_msg_id already used by the same sender in the same room returns that message's
     * id and changes nothing.
     *
     * @param list<Part> $parts
     * @param array{client_msg_id?: string, visibility?: string, private_to?: int, task_id?: int, status?: string, metadata?: array} $opts
     */
    public function create(int $roomId, Participant $p, array $parts, array $opts = []): int
    {
        if ($unknown = array_diff(array_keys($opts), self::OPTIONS)) {
            throw new InvalidArgumentException('Unknown message option: '.implode(', ', $unknown).'.');
        }
        [$senderKind, $senderId] = $this->sender($p);
        $this->assertPartList($parts);
        if (in_array($senderKind, ['human', 'agent'], true) && ($error = self::validateBody($this->joinText($parts))) !== null) {
            throw new InvalidArgumentException($error);
        }
        $partsJson = $this->encodeParts($parts);
        $bodyText = $this->plainText($parts);

        $clientMsgId = $opts['client_msg_id'] ?? null;
        if ($clientMsgId !== null && (! is_string($clientMsgId) || ! preg_match(self::CLIENT_MSG_ID, $clientMsgId))) {
            throw new InvalidArgumentException('client_msg_id must be 1-64 characters of A-Z, a-z, 0-9, _ or -.');
        }
        $visibility = $opts['visibility'] ?? 'room';
        if (! in_array($visibility, self::VISIBILITIES, true)) {
            throw new InvalidArgumentException('visibility must be room or private.');
        }
        $privateTo = isset($opts['private_to']) ? $this->positiveInt($opts['private_to'], 'private_to') : null;
        if (($visibility === 'private') !== ($privateTo !== null)) {
            throw new InvalidArgumentException('A private message needs private_to; a room message must not have it.');
        }
        // A person writes only into their own drawer thread; the bot answers into the asker's.
        if ($privateTo !== null && $senderKind === 'human' && $privateTo !== $senderId) {
            throw new InvalidArgumentException('A private message from a person must be addressed to that person.');
        }
        $taskId = isset($opts['task_id']) ? $this->positiveInt($opts['task_id'], 'task_id') : null;
        $status = $this->status($opts['status'] ?? MessageStatus::Done->value);
        $metadata = isset($opts['metadata']) ? $this->encodeMetadata($opts['metadata']) : null;

        if ($clientMsgId !== null && ($existing = $this->findByClientMsgId($roomId, $senderKind, $senderId, $clientMsgId)) !== null) {
            return $existing;
        }

        $now = time();
        $id = $this->db->table(self::TABLE)->persist([
            'room_id' => $roomId,
            'sender_kind' => $senderKind,
            'sender_id' => $senderId,
            'visibility' => $visibility,
            'private_to' => $privateTo,
            'status' => $status,
            'parts' => $partsJson,
            'body_text' => $bodyText,
            'metadata' => $metadata,
            'client_msg_id' => $clientMsgId,
            'task_id' => $taskId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($id) {
            return (int) $id;
        }
        // Lost a race on agentchat_messages_client_msg_uidx: the other request's row stands.
        // Bot and system rows have sender_id NULL, which that index never compares equal, so
        // for them the lookup before the insert is the only dedupe (accepted: they are server-driven).
        if ($clientMsgId !== null && ($existing = $this->findByClientMsgId($roomId, $senderKind, $senderId, $clientMsgId)) !== null) {
            return $existing;
        }
        throw new RuntimeException("Could not store the message in room #$roomId.");
    }

    public function getById(int $id): ?array
    {
        return $this->db->table(self::TABLE)->eq('id', $id)->findOne() ?: null;
    }

    /**
     * Room messages (visibility=room) with id > $afterId, oldest first, at most $limit.
     * Private messages never appear here, not even to their owner: they are read only through
     * privateThread(). $viewerId is the poller; access is checked by the caller (AccessPolicy).
     *
     * The poll endpoint (a later task) additionally filters out non-final bot drafts
     * (status submitted/streaming), which reach the asker through the stream instead.
     */
    public function listAfter(int $roomId, int $viewerId, int $afterId, int $limit = 50): array
    {
        return $this->db->table(self::TABLE)
            ->eq('room_id', $roomId)
            ->eq('visibility', 'room')
            ->gt('id', $afterId)
            ->asc('id')
            ->limit(max(1, min($limit, 200)))
            ->findAll();
    }

    /** The newest $limit messages of $userId's private drawer thread on $taskId, oldest first. */
    public function privateThread(int $roomId, int $userId, int $taskId, int $limit = 40): array
    {
        $rows = $this->db->table(self::TABLE)
            ->eq('room_id', $roomId)
            ->eq('visibility', 'private')
            ->eq('private_to', $userId)
            ->eq('task_id', $taskId)
            ->desc('id')
            ->limit(max(1, min($limit, 200)))
            ->findAll();
        return array_reverse($rows);
    }

    /**
     * Rewrites a message's parts (body_text follows), status or metadata.
     *
     * @param array{parts?: list<Part>, status?: string, metadata?: array} $fields
     */
    public function update(int $id, array $fields): void
    {
        $values = [];
        foreach ($fields as $key => $value) {
            match ($key) {
                'parts' => $values += ['parts' => $this->encodeParts($value), 'body_text' => $this->plainText($value)],
                'status' => $values['status'] = $this->status($value),
                'metadata' => $values['metadata'] = $this->encodeMetadata($value),
                default => throw new InvalidArgumentException("Field '$key' cannot be updated."),
            };
        }
        $values['updated_at'] = time();
        $this->db->table(self::TABLE)->eq('id', $id)->update($values);
    }

    /**
     * The stored row as a chatparts Message. A malformed row (bad JSON, unknown part type or
     * status, missing keys) never fails the page: it becomes an error-status message from the
     * same sender with one text part saying it can't be shown, and metadata unreadable=true.
     */
    public function toChatPartsMessage(array $row): Message
    {
        $participant = $this->participant($row);
        try {
            $parts = json_decode((string) $row['parts'], true, 512, JSON_THROW_ON_ERROR);
            $metadata = $row['metadata'] === null || $row['metadata'] === '' ? [] : json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR);
            return Message::fromArray([
                'id' => (string) $row['id'],
                'room' => (string) $row['room_id'],
                'participant' => $participant->toArray(),
                'status' => (string) $row['status'],
                'parts' => $parts,
                'metadata' => $metadata,
                'createdAt' => (int) $row['created_at'],
            ]);
        } catch (\JsonException|InvalidArgumentException $e) {
            return new Message(
                (string) $row['id'],
                (string) $row['room_id'],
                $participant,
                MessageStatus::Error,
                [new TextPart('unreadable', t('This message can\'t be shown.'))],
                ['unreadable' => true],
                (int) $row['created_at'],
            );
        }
    }

    /** @return array{0: string, 1: ?int} sender_kind, sender_id */
    private function sender(Participant $p): array
    {
        if (! in_array($p->kind, self::SENDER_KINDS, true)) {
            throw new InvalidArgumentException("Unknown participant kind '{$p->kind}'.");
        }
        if ($p->kind === 'bot' || $p->kind === 'system') {
            return [$p->kind, null];
        }
        if ($p->id === null || ! ctype_digit($p->id) || (int) $p->id < 1) {
            throw new InvalidArgumentException("A {$p->kind} participant needs a user id.");
        }
        return [$p->kind, (int) $p->id];
    }

    private function participant(array $row): Participant
    {
        $kind = (string) $row['sender_kind'];
        return match ($kind) {
            'bot' => new Participant('bot', 'project', t('Project bot')),
            'human', 'agent' => new Participant($kind, (string) $row['sender_id'], $this->userName((int) $row['sender_id'])),
            default => new Participant($kind, null, t('System')),
        };
    }

    private function userName(int $userId): string
    {
        if (! isset($this->names[$userId])) {
            $user = $this->userModel->getById($userId);
            $this->names[$userId] = empty($user) ? t('Unknown user') : (string) ($user['name'] ?: $user['username']);
        }
        return $this->names[$userId];
    }

    private function findByClientMsgId(int $roomId, string $senderKind, ?int $senderId, string $clientMsgId): ?int
    {
        $query = $this->db->table(self::TABLE)
            ->eq('room_id', $roomId)
            ->eq('sender_kind', $senderKind)
            ->eq('client_msg_id', $clientMsgId);
        $senderId === null ? $query->isNull('sender_id') : $query->eq('sender_id', $senderId);
        $id = $query->findOneColumn('id');
        return $id ? (int) $id : null;
    }

    private function encodeParts(mixed $parts): string
    {
        $this->assertPartList($parts);
        return $this->json(array_map(static fn (Part $part): array => $part->toArray(), $parts), 'parts');
    }

    private function assertPartList(mixed $parts): void
    {
        if (! is_array($parts) || ! array_is_list($parts)) {
            throw new InvalidArgumentException('parts must be a list of chatparts parts.');
        }
        foreach ($parts as $part) {
            if (! $part instanceof Part) {
                throw new InvalidArgumentException('parts must be a list of chatparts parts, got '.get_debug_type($part).'.');
            }
        }
    }

    /** The derived body_text: joinText() without NUL bytes, which Postgres TEXT cannot hold. @param list<Part> $parts */
    private function plainText(array $parts): string
    {
        return str_replace("\0", '', $this->joinText($parts));
    }

    /** Text parts joined by a blank line, as chatparts' Message::plainText(). @param list<Part> $parts */
    private function joinText(array $parts): string
    {
        $texts = [];
        foreach ($parts as $part) {
            if ($part instanceof TextPart) {
                $texts[] = $part->text;
            }
        }
        return implode("\n\n", $texts);
    }

    private function status(mixed $status): string
    {
        if (! is_string($status) || MessageStatus::tryFrom($status) === null) {
            throw new InvalidArgumentException('Unknown message status.');
        }
        return $status;
    }

    private function encodeMetadata(mixed $metadata): string
    {
        if (! is_array($metadata)) {
            throw new InvalidArgumentException('metadata must be an array.');
        }
        return $this->json((object) $metadata, 'metadata');
    }

    /** JSON for storage; unencodable input (invalid UTF-8, too deep) is the caller's bad argument. */
    private function json(mixed $value, string $what): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException("$what cannot be stored: {$e->getMessage()}.", 0, $e);
        }
    }

    private function positiveInt(mixed $value, string $name): int
    {
        if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || (int) $value < 1) {
            throw new InvalidArgumentException("$name must be a positive integer.");
        }
        return (int) $value;
    }
}
