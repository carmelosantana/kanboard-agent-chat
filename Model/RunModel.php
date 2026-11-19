<?php

namespace Kanboard\Plugin\AgentChat\Model;

use ChatParts\Model\MessageStatus;
use ChatParts\Model\Part\TextPart;
use Kanboard\Core\Base;
use Kanboard\Plugin\AgentChat\Bot\DbCancellationToken;

/**
 * The life of one bot answer in agentchat_runs: pending (created with a single-use stream id)
 * → running (claimed once, by the stream or by a synchronous run) → done, stopped or error.
 *
 * Limits are checked when a run is created, after stale runs are reaped: questions per user
 * per hour, answers running at once per user and on the whole instance (pending and running
 * both count). The checks are not atomic with the insert, so two requests in the same instant
 * can both pass; the limits are soft by that one request.
 *
 * started_at is when the run was created, then when it was claimed: the hourly count and the
 * reaper read it. The reaper finalises a run still pending or running GRACE_SECONDS after the
 * run time limit as an error, so a crashed or abandoned stream never holds a slot or stalls
 * an API reader (MessageModel::listSettledAfter) for longer than that.
 *
 * The stream id itself is never stored, only its sha256.
 *
 * One run per question: the bot's draft replies to the asker's message (reply_to), and asking
 * again for a message that already has one (a retried send with the same client_msg_id) gets
 * that run back, with no sid, whatever the limits say. A send that was refused by a limit left
 * no draft, so its retry may start the run once the limits allow. This is a guarded check, not
 * a constraint: agentchat_runs.message_id is the bot's draft, which a retry would create anew,
 * and a unique index on the draft's reply_to needs a partial index MySQL lacks. Two retries in
 * the same instant are also held back by the one-answer-per-user limit.
 */
class RunModel extends Base
{
    public const TABLE = 'agentchat_runs';
    /** Past the run time limit: the longest legitimate run also waits out one 30 s provider idle timeout. */
    public const GRACE_SECONDS = 60;
    private const ACTIVE = ['pending', 'running'];

    /**
     * The bot's draft (submitted, empty, replying to $questionId) and a pending run for it,
     * once the limits allow. A question that already has a draft and run gets those back with
     * sid null. $draftOpts are the draft's visibility, private_to and task_id: a drawer
     * question's draft is private to its asker, on its task.
     *
     * @param array{visibility?: string, private_to?: int, task_id?: int} $draftOpts
     * @return array{run_id: int, sid: ?string, message_id: int}
     * @throws RateLimited|Busy
     */
    public function ask(int $roomId, int $askedBy, int $questionId, array $draftOpts = []): array
    {
        $draftId = $this->db->table(MessageModel::TABLE)
            ->eq('room_id', $roomId)
            ->eq('sender_kind', 'bot')
            ->eq('reply_to', $questionId)
            ->asc('id')
            ->findOneColumn('id');
        if ($draftId && ($runId = $this->runFor((int) $draftId)) !== null) {
            return ['run_id' => $runId, 'sid' => null, 'message_id' => (int) $draftId];
        }

        $this->assertAllowed($askedBy);
        $messageId = (new MessageModel($this->container))->create(
            $roomId,
            (new ParticipantResolver($this->container))->bot(),
            [],
            ['status' => MessageStatus::Submitted->value, 'reply_to' => $questionId] + array_intersect_key($draftOpts, array_flip(['visibility', 'private_to', 'task_id'])),
        );
        return $this->insert($messageId, $roomId, $askedBy) + ['message_id' => $messageId];
    }

    /**
     * A pending run for the bot message $messageId, once the limits allow. sid is 32 random
     * bytes as hex; only its hash is stored. A message that already has a run gets it back
     * with sid null.
     *
     * @return array{run_id: int, sid: ?string}
     * @throws RateLimited|Busy
     */
    public function start(int $messageId, int $roomId, int $askedBy): array
    {
        if (($runId = $this->runFor($messageId)) !== null) {
            return ['run_id' => $runId, 'sid' => null];
        }
        $this->assertAllowed($askedBy);
        return $this->insert($messageId, $roomId, $askedBy);
    }

    /**
     * Takes the pending run behind $sid for $userId, atomically: exactly one caller ever gets
     * it, and only its asker. Null for an unknown, spent or finished sid or another user's.
     */
    public function claim(string $sid, int $userId): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sid) !== 1) {
            return null;
        }
        $hash = hash('sha256', $sid);
        if (! $this->claimWhere('stream_token_hash = ? AND asked_by = ?', [$hash, $userId])) {
            return null;
        }
        return $this->db->table(self::TABLE)->eq('stream_token_hash', $hash)->findOne() ?: null;
    }

    /** Takes a pending run by id (a run nobody streams); false when it is not pending. */
    public function claimById(int $runId): bool
    {
        return $this->claimWhere('id = ?', [$runId]);
    }

    /**
     * Asks a run to stop: $flag is DbCancellationToken::STOPPED_BY_ASKER for the asker's own
     * stop, STOPPED for a client that went away. False when the run is unknown or not
     * $userId's; true otherwise, also for a finished run, which is left as it is.
     */
    public function requestStop(int $runId, int $userId, int $flag = DbCancellationToken::STOPPED_BY_ASKER): bool
    {
        $askedBy = $this->db->table(self::TABLE)->eq('id', $runId)->findOneColumn('asked_by');
        if ($askedBy === false || $askedBy === null || (int) $askedBy !== $userId) {
            return false;
        }
        $this->db->table(self::TABLE)->eq('id', $runId)->in('status', self::ACTIVE)->update(['stop_requested' => $flag]);
        return true;
    }

    public function get(int $runId): ?array
    {
        return $this->db->table(self::TABLE)->eq('id', $runId)->findOne() ?: null;
    }

    /** @return list<int> the ids of $userId's pending or running runs in $roomId */
    public function activeIds(int $userId, int $roomId): array
    {
        return array_map('intval', $this->db->table(self::TABLE)
            ->eq('asked_by', $userId)
            ->eq('room_id', $roomId)
            ->in('status', self::ACTIVE)
            ->asc('id')
            ->findAllByColumn('id'));
    }

    /**
     * Closes the run row (its final status, the time, the usage summary as JSON) if it is still
     * pending or running; false, and nothing changed, when it already ended (the reaper or a
     * failure got there first).
     */
    public function finish(int $runId, string $status, array $usage): bool
    {
        return $this->closeActive($runId, $status, $usage);
    }

    /**
     * Finalises every run still pending or running GRACE_SECONDS past the run time limit as an
     * error (error_code timed_out). Returns how many were reaped.
     */
    public function reap(): int
    {
        $cutoff = time() - (int) ceil((new LimitsSettings($this->container))->maxRunSeconds()) - self::GRACE_SECONDS;
        $stale = $this->db->table(self::TABLE)->in('status', self::ACTIVE)->lt('started_at', $cutoff)->findAllByColumn('id');

        return count(array_filter(array_map(fn ($id): bool => $this->fail((int) $id, 'timed_out'), $stale)));
    }

    /**
     * Ends a run that is still pending or running as an error: the run row (with $errorCode)
     * and its message (status error, the generic text and a receipt). False, and nothing
     * changed, when the run already finished.
     */
    public function fail(int $runId, string $errorCode): bool
    {
        if (! $this->closeActive($runId, 'error', ['error_code' => $errorCode])) {
            return false;
        }
        (new MessageModel($this->container))->update((int) $this->db->table(self::TABLE)->eq('id', $runId)->findOneColumn('message_id'), [
            'parts' => [new TextPart("r$runId-t1", t('The bot could not answer.'))],
            'status' => MessageStatus::Error->value,
            'metadata' => ['bot' => (new ParticipantResolver($this->container))->bot()->name, 'finish_reason' => 'error'],
        ]);
        return true;
    }

    /** active → $status, atomically; true only when this call changed the row. */
    private function closeActive(int $runId, string $status, array $usage): bool
    {
        $placeholders = implode(', ', array_fill(0, count(self::ACTIVE), '?'));
        $statement = $this->db->execute(
            'UPDATE '.self::TABLE.' SET status = ?, finished_at = ?, '.$this->db->escapeIdentifier('usage')." = ? WHERE id = ? AND status IN ($placeholders)",
            [$status, time(), json_encode($usage), $runId, ...self::ACTIVE],
        );
        return $statement !== false && $statement->rowCount() === 1;
    }

    private function runFor(int $messageId): ?int
    {
        $id = $this->db->table(self::TABLE)->eq('message_id', $messageId)->asc('id')->findOneColumn('id');
        return $id ? (int) $id : null;
    }

    /** @throws RateLimited|Busy */
    private function assertAllowed(int $askedBy): void
    {
        $this->reap();
        $limits = new LimitsSettings($this->container);

        $perHour = $limits->questionsPerHour();
        if ($this->db->table(self::TABLE)->eq('asked_by', $askedBy)->gte('started_at', time() - 3600)->count() >= $perHour) {
            throw new RateLimited(t('You have reached the hourly question limit for the bot (%d). Try again later.', $perHour));
        }
        if ($this->db->table(self::TABLE)->eq('asked_by', $askedBy)->in('status', self::ACTIVE)->count() >= $limits->maxConcurrentPerUser()) {
            throw new Busy(t('The bot is still answering your last question.'));
        }
        if ($this->db->table(self::TABLE)->in('status', self::ACTIVE)->count() >= $limits->maxConcurrentInstance()) {
            throw new Busy(t('The bot is busy answering other questions. Try again in a minute.'));
        }
    }

    /** @return array{run_id: int, sid: string} */
    private function insert(int $messageId, int $roomId, int $askedBy): array
    {
        $sid = bin2hex(random_bytes(32));
        $id = $this->db->table(self::TABLE)->persist([
            'message_id' => $messageId,
            'room_id' => $roomId,
            'asked_by' => $askedBy,
            'stream_token_hash' => hash('sha256', $sid),
            'status' => 'pending',
            'started_at' => time(),
        ]);
        if (! $id) {
            throw new \RuntimeException("Could not start a bot run for message #$messageId.");
        }
        return ['run_id' => (int) $id, 'sid' => $sid];
    }

    /** pending → running for the one row $where names; true only when this call changed it. */
    private function claimWhere(string $where, array $values): bool
    {
        $statement = $this->db->execute(
            'UPDATE '.self::TABLE." SET status = ?, started_at = ? WHERE $where AND status = ?",
            ['running', time(), ...$values, 'pending'],
        );
        return $statement !== false && $statement->rowCount() === 1;
    }
}
