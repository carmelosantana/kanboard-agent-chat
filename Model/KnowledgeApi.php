<?php

namespace Kanboard\Plugin\AgentChat\Model;

use InvalidArgumentException;
use JsonRPC\Exception\AccessDeniedException;
use Kanboard\Core\Base;
use RuntimeException;

/**
 * The knowledgeUpsert / knowledgeDelete procedures behind AgentChatKnowledgeProcedure.
 *
 * Who may write: with a user's API credentials (password or personal token), a manager of the
 * target project or an app admin (AccessPolicy::canManage; inactive users and unknown projects
 * are refused alike, so a project id does not reveal whether the project exists). With the
 * application token there is no user, and Kanboard gives that token full API access, so the
 * write is allowed into any existing project.
 *
 * Errors reach the client as JSON-RPC errors with plain messages: bad params are an
 * InvalidArgumentException (-32602, the message in `data`), refusals an AccessDeniedException
 * (403), and any storage failure a fixed RuntimeException message, never SQL or a trace.
 */
class KnowledgeApi extends Base
{
    public const STORAGE_ERROR = 'The knowledge store could not save this document.';

    /** @return array{ok: true, id: int} */
    public function upsert(mixed $projectId, mixed $source, mixed $externalId, array $doc): array
    {
        [$projectId, $source, $externalId] = $this->key($projectId, $source, $externalId);
        $this->authorise($projectId);

        // null is how a JSON-RPC client leaves an optional field out.
        $doc = array_filter($doc, static fn (mixed $v): bool => $v !== null);

        return ['ok' => true, 'id' => $this->guard(fn () => (new KnowledgeModel($this->container))->upsert($projectId, $source, $externalId, $doc))];
    }

    public function delete(mixed $projectId, mixed $source, mixed $externalId): bool
    {
        [$projectId, $source, $externalId] = $this->key($projectId, $source, $externalId);
        $this->authorise($projectId);

        return $this->guard(fn () => (new KnowledgeModel($this->container))->delete($projectId, $source, $externalId));
    }

    /** @return array{int, string, string} */
    private function key(mixed $projectId, mixed $source, mixed $externalId): array
    {
        if (is_string($projectId) && ctype_digit($projectId)) {
            $projectId = (int) $projectId;
        }
        if (! is_int($projectId) || $projectId < 1) {
            throw new InvalidArgumentException('project_id must be a positive integer.');
        }
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

    private function guard(callable $write): mixed
    {
        try {
            return $write();
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('AgentChat knowledge write failed: '.$e->getMessage());
            throw new RuntimeException(self::STORAGE_ERROR);
        }
    }
}
