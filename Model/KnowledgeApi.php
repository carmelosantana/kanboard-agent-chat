<?php

namespace Kanboard\Plugin\AgentChat\Model;

use InvalidArgumentException;
use JsonRPC\Exception\AccessDeniedException;

/**
 * The knowledgeUpsert / knowledgeDelete procedures behind AgentChatKnowledgeProcedure.
 *
 * Who may write: with a user's API credentials (password or personal token), a manager of the
 * target project or an app admin (AccessPolicy::canManage; inactive users and unknown projects
 * are refused alike, so a project id does not reveal whether the project exists). With the
 * application token there is no user, and Kanboard gives that token full API access, so the
 * write is allowed into any existing project.
 *
 * Errors reach the client as JSON-RPC errors with plain messages (see RpcCall): bad params
 * -32602, refusals 403, and any storage failure, the access check included, STORAGE_ERROR with
 * code -32603.
 */
class KnowledgeApi extends RpcCall
{
    public const STORAGE_ERROR = 'The knowledge store could not save this document.';

    /** @return array{ok: true, id: int} */
    public function upsert(mixed $projectId, mixed $source, mixed $externalId, array $doc): array
    {
        [$projectId, $source, $externalId] = $this->key($projectId, $source, $externalId);

        // null is how a JSON-RPC client leaves an optional field out.
        $doc = array_filter($doc, static fn (mixed $v): bool => $v !== null);

        return ['ok' => true, 'id' => $this->guard(function () use ($projectId, $source, $externalId, $doc) {
            $this->authorise($projectId);
            return (new KnowledgeModel($this->container))->upsert($projectId, $source, $externalId, $doc);
        }, self::STORAGE_ERROR)];
    }

    public function delete(mixed $projectId, mixed $source, mixed $externalId): bool
    {
        [$projectId, $source, $externalId] = $this->key($projectId, $source, $externalId);

        return $this->guard(function () use ($projectId, $source, $externalId) {
            $this->authorise($projectId);
            return (new KnowledgeModel($this->container))->delete($projectId, $source, $externalId);
        }, self::STORAGE_ERROR);
    }

    /** @return array{int, string, string} */
    private function key(mixed $projectId, mixed $source, mixed $externalId): array
    {
        $projectId = self::integer($projectId, 'project_id', 1);
        if (! is_string($source) || ! is_string($externalId)) {
            throw new InvalidArgumentException('source and external_id must be strings.');
        }
        return [$projectId, $source, $externalId];
    }

    private function authorise(int $projectId): void
    {
        if ($this->userSession->isLogged()) {
            if (! (new AccessPolicy($this->container))->canManage((int) $this->userSession->getId(), $projectId)) {
                throw new AccessDeniedException('Forbidden');
            }
            return;
        }
        // Application token.
        if (! $this->projectModel->exists($projectId)) {
            throw new InvalidArgumentException('Unknown project.');
        }
    }
}
