<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use Pimple\Container;

/**
 * A run's stop flag, read from agentchat_runs.stop_requested at most every 250 ms (the agent
 * asks between every stream chunk). Once a stop is seen it stays seen.
 *
 * stop_requested: 0 runs on, STOPPED_BY_ASKER is the asker's own stop, any other value a stop
 * with no named person (a client that went away). The column is a TINYINT on MySQL, so it can
 * hold no user id.
 */
final class DbCancellationToken implements CancellationTokenInterface
{
    public const STOPPED_BY_ASKER = 1;
    public const STOPPED = 2;
    private const MIN_INTERVAL_NS = 250_000_000;

    private int $request = 0;
    private ?float $readAt = null;

    public function __construct(private readonly Container $container, private readonly int $runId)
    {
    }

    public function isCancelled(): bool
    {
        if ($this->request !== 0) {
            return true;
        }
        $now = (float) hrtime(true);
        if ($this->readAt === null || $now - $this->readAt >= self::MIN_INTERVAL_NS) {
            $this->readAt = $now;
            $this->request = (int) $this->container['db']->table('agentchat_runs')->eq('id', $this->runId)->findOneColumn('stop_requested');
        }
        return $this->request !== 0;
    }

    /** The stop_requested value seen (0 while none was). */
    public function stopRequest(): int
    {
        return $this->request;
    }
}
