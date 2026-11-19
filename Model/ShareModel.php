<?php

namespace Kanboard\Plugin\AgentChat\Model;

use ChatParts\Model\MessageStatus;
use ChatParts\Model\Part\FilePart;
use ChatParts\Model\Part\Part;
use Kanboard\Core\Base;
use RuntimeException;
use Throwable;

/**
 * "Share to room" in a task drawer: the asker's latest question and the bot's answer to it,
 * copied out of their private thread as two room messages (the question as theirs, the answer
 * as the bot's, keeping its parts, status and metadata). The private thread is left as it was.
 *
 * Images on either message are copied too (FileModel::copy: new rows, new blobs, the room
 * copy's message), never shared by key. It is both messages or neither: a copy that fails
 * removes every blob and row this share wrote.
 *
 * Part ids get a fresh prefix per share (bubble DOM ids derive from them), file parts the new
 * file's id and URLs.
 *
 * An answer is shared once: the private answer's metadata records the room copies
 * (`shared_as`: [question id, answer id]), and sharing it again is refused. The check is not
 * atomic with the copy, so two shares in the same instant could both pass.
 */
class ShareModel extends Base
{
    private const FINAL = [MessageStatus::Done->value, MessageStatus::Stopped->value];

    /**
     * Shares the latest pair of $userId's thread on $taskId in $roomId; returns the room copies'
     * ids [question, answer].
     *
     * @return array{0: int, 1: int}
     * @throws NothingToShare when there is no finished answer to share, or it was shared already
     * @throws Throwable when a copy fails (nothing was posted)
     */
    public function share(int $roomId, int $userId, int $taskId): array
    {
        [$question, $answer] = $this->latestPair($roomId, $userId, $taskId);
        $files = new FileModel($this->container);
        $messages = new MessageModel($this->container);
        $resolver = new ParticipantResolver($this->container);
        $prefix = 's'.bin2hex(random_bytes(4)).'-';

        $copied = [];
        try {
            $parts = [];
            $fileIds = [];
            foreach (['question' => $question, 'answer' => $answer] as $which => $row) {
                $map = [];
                foreach ($files->forMessage((int) $row['id']) as $file) {
                    $copy = $files->copy($file, $roomId);
                    $copied[] = $copy;
                    $map['f'.$file['id']] = $files->part($files->get($copy));
                    $fileIds[$which][] = $copy;
                }
                $parts[$which] = $this->copyParts($row, $map, $prefix);
            }

            $this->db->startTransaction();
            try {
                $questionId = $messages->create($roomId, $resolver->forUser($userId), $parts['question']);
                $answerId = $messages->create($roomId, $resolver->bot(), $parts['answer'], [
                    'status' => (string) $answer['status'],
                    'reply_to' => $questionId,
                ] + ($this->metadata($answer) === [] ? [] : ['metadata' => $this->metadata($answer)]));
                if (! $files->linkToMessage($fileIds['question'] ?? [], $questionId) || ! $files->linkToMessage($fileIds['answer'] ?? [], $answerId)) {
                    throw new RuntimeException('The shared images could not be linked.');
                }
                $messages->update((int) $answer['id'], ['metadata' => $this->metadata($answer) + ['shared_as' => [$questionId, $answerId]]]);
                $this->db->closeTransaction();
            } catch (Throwable $e) {
                $this->db->cancelTransaction();
                throw $e;
            }
            return [$questionId, $answerId];
        } catch (Throwable $e) {
            $files->remove($copied);
            throw $e;
        }
    }

    /**
     * The newest bot message of the thread, if finished (done or stopped), and the question it
     * answers, which must be $userId's own message in the same thread.
     *
     * @return array{0: array, 1: array}
     * @throws NothingToShare
     */
    private function latestPair(int $roomId, int $userId, int $taskId): array
    {
        $messages = new MessageModel($this->container);
        $bots = array_filter($messages->privateThread($roomId, $userId, $taskId), static fn (array $row): bool => $row['sender_kind'] === 'bot');
        $answer = $bots === [] ? null : end($bots);
        if ($answer === null || ! in_array($answer['status'], self::FINAL, true)) {
            throw new NothingToShare(t('There is no finished answer to share yet.'));
        }
        if (isset($this->metadata($answer)['shared_as'])) {
            throw new NothingToShare(t('Already shared to the room.'));
        }
        $question = $answer['reply_to'] === null ? null : $messages->getById((int) $answer['reply_to']);
        if ($question === null
            || (int) $question['room_id'] !== $roomId
            || $question['visibility'] !== 'private'
            || (int) $question['private_to'] !== $userId
            || (int) $question['task_id'] !== $taskId
            || (int) $question['sender_id'] !== $userId) {
            throw new NothingToShare(t('There is no finished answer to share yet.'));
        }
        return [$question, $answer];
    }

    /**
     * The row's parts for the room copy: file parts replaced by their copies (a file part with
     * no stored file is dropped), every other part under a fresh id.
     *
     * @param array<string, FilePart> $files old part id => the copy's part
     * @return list<Part>
     */
    private function copyParts(array $row, array $files, string $prefix): array
    {
        $parts = [];
        foreach (json_decode((string) $row['parts'], true, 512, JSON_THROW_ON_ERROR) as $part) {
            if (($part['type'] ?? null) === 'file') {
                if (isset($files[$part['id'] ?? ''])) {
                    $parts[] = $files[$part['id']];
                }
                continue;
            }
            $part['id'] = $prefix.$part['id'];
            $parts[] = Part::fromArray($part);
        }
        return $parts;
    }

    /** The row's metadata as an array ([] when it has none). */
    private function metadata(array $row): array
    {
        return $row['metadata'] === null || $row['metadata'] === '' ? [] : (array) json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR);
    }
}
