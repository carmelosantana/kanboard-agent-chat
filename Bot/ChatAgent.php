<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\EmptyResponseHandling;

/**
 * The project bot as a php-agents agent: fixed instructions, the read-only tools of one run,
 * the run limits and a stop token. A model that answers only in its reasoning is nudged, then
 * its reasoning is used as the answer.
 */
final class ChatAgent extends AbstractAgent
{
    /** @param list<ToolInterface> $chatTools */
    public function __construct(
        ProviderInterface $provider,
        private readonly string $instructions,
        private readonly array $chatTools,
        int $maxIterations,
        RunLimits $runLimits,
        ?CancellationTokenInterface $cancellationToken = null,
    ) {
        parent::__construct(
            $provider,
            $maxIterations,
            cancellationToken: $cancellationToken,
            emptyResponseHandling: EmptyResponseHandling::NudgeThenFallback,
            runLimits: $runLimits,
        );
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function tools(): array
    {
        return $this->chatTools;
    }
}
