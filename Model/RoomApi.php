<?php

namespace Kanboard\Plugin\AgentChat\Model;

use ChatParts\Model\Part\TextPart;
use InvalidArgumentException;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\ResponseException;
use Kanboard\Plugin\AgentChat\Bot\BotRunner;
use Kanboard\Plugin\AgentChat\Bot\Mention;

/**
 * The chatSendMessage / chatRoomMessages procedures behind AgentChatRoomProcedure: agents (and
 * people) post to and read a project's room with their own API credentials.
 *
 * Both need a user session (username + password or personal token); the application token has
 * no user, so it is refused (403 Forbidden, `data` says a user token is required): a post needs a
 * sender and reads run as a user. Posting needs
 * AccessPolicy::canPost, reading canView; inactive users and unknown projects are refused alike.
 * The room is always the project's room; a client never names a room, sender or visibility.
 *
 * Reads return room messages only (never private ones), oldest first, with id > after. A page
 * ends just before the first unfinished bot message (submitted or streaming), so a reader gets
 * complete answers and never skips one; it sees nothing newer until that answer is final. The
 * wait is bounded: the run lifecycle finalises a crashed or stuck run as error within the run
 * time limit plus a grace period. Errors are mapped as RpcCall describes.
 */
class RoomApi extends RpcCall
{
    public const SEND_FAILED = 'The chat could not save this message.';
    public const READ_FAILED = 'The chat could not be read.';
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    /**
     * Stores $body as one text part from the token's user and returns its id; a client_msg_id
     * this user already used in the room returns that message's id.
     *
     * A body mentioning @bot from a user who may ask, while the project's bot gate is open, is
     * answered within this request (no stream; up to the run time limit) and bot_message_id
     * names the answer, whatever its status. A retry with the same client_msg_id gets the same
     * answer back; it never starts a second run. Over a run limit the message is still stored,
     * bot_message_id is null and bot_error says why. Without the right or the gate the
     * result is the message id alone.
     *
     * @return array{message_id: int, bot_message_id?: ?int, bot_error?: string}
     */
    public function send(mixed $projectId, mixed $body, mixed $clientMsgId): array
    {
        $projectId = self::integer($projectId, 'project_id', 1);

        return $this->guard(function () use ($projectId, $body, $clientMsgId): array {
            $userId = $this->allowedUser(fn (AccessPolicy $policy, int $userId): bool => $policy->canPost($userId, $projectId));
            if (! is_string($body)) {
                throw new InvalidArgumentException(t('Write a message first.'));
            }
            if (strlen($body) > MessageModel::MAX_REQUEST_BYTES) {
                throw new InvalidArgumentException(t('This message is too long to send.'));
            }

            $room = (new RoomModel($this->container))->forProject($projectId);
            $id = (new MessageModel($this->container))->create(
                (int) $room['id'],
                (new ParticipantResolver($this->container))->forUser($userId),
                [new TextPart('t'.bin2hex(random_bytes(8)), $body)],
                $clientMsgId === null || $clientMsgId === '' ? [] : ['client_msg_id' => $clientMsgId],
            );
            if (! Mention::ofBot($body) || ! (new AccessPolicy($this->container))->canAsk($userId, $projectId) || ! AiGate::isOpenFor($this->container, $projectId)) {
                return ['message_id' => $id];
            }
            try {
                $turn = (new RunModel($this->container))->ask((int) $room['id'], $userId, $id);
            } catch (RateLimited|Busy $e) {
                return ['message_id' => $id, 'bot_message_id' => null, 'bot_error' => $e->getMessage()];
            }
            @set_time_limit((int) ceil((new LimitsSettings($this->container))->maxRunSeconds()) + RunModel::GRACE_SECONDS);
            BotRunner::forContainer($this->container)->runToCompletion($turn['run_id']);
            return ['message_id' => $id, 'bot_message_id' => $turn['message_id']];
        }, self::SEND_FAILED);
    }

    /** @return list<array{id: int, sender_kind: string, sender_name: string, text: string, created_at: int}> */
    public function read(mixed $projectId, mixed $after, mixed $limit): array
    {
        $projectId = self::integer($projectId, 'project_id', 1);
        $after = $after === null ? 0 : self::integer($after, 'after', 0);
        $limit = $limit === null ? self::DEFAULT_LIMIT : max(1, min(self::MAX_LIMIT, self::integer($limit, 'limit', PHP_INT_MIN)));

        return $this->guard(function () use ($projectId, $after, $limit): array {
            $this->allowedUser(fn (AccessPolicy $policy, int $userId): bool => $policy->canView($userId, $projectId));
            // Read-only: a room nobody has opened yet has no messages.
            $room = (new RoomModel($this->container))->findForProject($projectId);
            if ($room === null) {
                return [];
            }

            $messages = new MessageModel($this->container);
            return array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'sender_kind' => (string) $row['sender_kind'],
                'sender_name' => $messages->toChatPartsMessage($row)->participant->name,
                'text' => (string) $row['body_text'],
                'created_at' => (int) $row['created_at'],
            ], $messages->listSettledAfter((int) $room['id'], $after, $limit));
        }, self::READ_FAILED);
    }

    /** The session user's id when $allowed says yes; the application token (no user) is refused. */
    private function allowedUser(callable $allowed): int
    {
        if (! $this->userSession->isLogged()) {
            throw new ResponseException('Forbidden', 403, null, 'A user API token is required; the application token has no user.');
        }
        $userId = (int) $this->userSession->getId();
        if (! $allowed(new AccessPolicy($this->container), $userId)) {
            throw new AccessDeniedException('Forbidden');
        }
        return $userId;
    }
}
