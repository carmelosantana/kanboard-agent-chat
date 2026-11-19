<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\EmptyResponseHandling;
use Kanboard\Plugin\AgentChat\Bot\Tools\GetTaskTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchCommentsTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchKnowledgeTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchTasksTool;

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

    /**
     * The bot's read-only tools, reading as $scope and numbering sources in $sources.
     *
     * @return list<ToolInterface>
     */
    public static function readTools(AskerScope $scope, SourceRegistry $sources): array
    {
        return [new SearchTasksTool($scope, $sources), new GetTaskTool($scope, $sources), new SearchCommentsTool($scope, $sources), new SearchKnowledgeTool($scope, $sources)];
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
