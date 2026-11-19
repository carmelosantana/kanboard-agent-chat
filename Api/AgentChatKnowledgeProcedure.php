<?php

namespace Kanboard\Plugin\AgentChat\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Plugin\AgentChat\Model\KnowledgeApi;

// JSON-RPC: push knowledge documents into a project. Prefixed short name because core's ACL maps
// key on short class names. Holds ONLY RPC methods (every method on a withObject instance is
// remotely callable, private ones included) and declares params without type hints (a TypeError
// is a fatal with no JSON-RPC body). Access, validation and error mapping live in KnowledgeApi.
class AgentChatKnowledgeProcedure extends BaseProcedure
{
    public function knowledgeUpsert($project_id, $source, $external_id, $title, $body, $url = '', $author = '', $occurred_at = 0, $metadata = [])
    {
        return (new KnowledgeApi($this->container))->upsert($project_id, $source, $external_id, [
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'author' => $author,
            'occurred_at' => $occurred_at,
            'metadata' => $metadata,
        ]);
    }

    public function knowledgeDelete($project_id, $source, $external_id)
    {
        return (new KnowledgeApi($this->container))->delete($project_id, $source, $external_id);
    }
}
