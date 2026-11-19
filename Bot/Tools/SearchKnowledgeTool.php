<?php

namespace Kanboard\Plugin\AgentChat\Bot\Tools;

use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Kanboard\Plugin\AgentChat\Model\KnowledgeModel;

/**
 * Full-text search over the room project's knowledge documents. KnowledgeModel::search checks
 * no access of its own; the asker's view of the project is checked before every call.
 */
class SearchKnowledgeTool extends ReadOnlyTool
{
    public const MAX_LIMIT = 8;

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Full-text search over this project\'s knowledge documents (saved posts, notes), best match first.';
    }

    public function parameters(): array
    {
        return [
            new StringParameter('query', 'Words to look for.', true, maxLength: self::MAX_QUERY),
            new StringParameter('source', 'Only documents from this source, e.g. save-it.', false, maxLength: KnowledgeModel::MAX_SOURCE),
            new NumberParameter('limit', 'Most results to return; default '.self::MAX_LIMIT.'.', false, integer: true, minimum: 1, maximum: self::MAX_LIMIT),
        ];
    }

    protected function run(array $input): ToolResult
    {
        $hits = (new KnowledgeModel($this->scope->container()))->search($this->scope->projectId, $input['query'], $input['source'] ?? null, $input['limit'] ?? self::MAX_LIMIT);

        return $this->result(['results' => array_map(function (array $hit): array {
            $title = self::text($hit['title']);
            return [
                'n' => $this->sources->register('knowledge', (string) $hit['id'], $title, $this->url('KnowledgeController', 'show', ['id' => $hit['id'], 'plugin' => 'AgentChat'])),
                'knowledge_id' => $hit['id'],
                'title' => $title,
                'source' => self::text($hit['source']),
                'date' => self::date($hit['occurred_at']),
                'snippet' => self::text($hit['snippet']),
            ];
        }, $hits)]);
    }
}
