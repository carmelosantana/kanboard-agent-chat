<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Pimple\Container;

/**
 * Who the bot reads as and where: the asking user, the room's project and, in a task drawer,
 * that task. Every tool is confined to the room's project and runs with the asker's rights,
 * re-checked on each call: an asker who cannot view the project (now or at construction)
 * reads nothing. A task outside the room's project reads exactly like a missing one.
 */
class AskerScope extends Base
{
    public readonly ?int $taskId;
    private readonly bool $allowed;

    public function __construct(Container $container, public readonly int $userId, public readonly int $projectId, ?int $taskId = null)
    {
        parent::__construct($container);
        $this->allowed = (new AccessPolicy($container))->canView($userId, $projectId);
        $this->taskId = $taskId !== null && $this->canReadTask($taskId) ? $taskId : null;
    }

    public function canViewProject(): bool
    {
        return $this->allowed && (new AccessPolicy($this->container))->canView($this->userId, $this->projectId);
    }

    /** True only for an existing task of the room's project, while the asker can view that project. */
    public function canReadTask(int $taskId): bool
    {
        return $taskId > 0
            && $this->canViewProject()
            && $this->taskFinderModel->getProjectId($taskId) === $this->projectId;
    }

    /**
     * Comment visibilities the asker may read, as Kanboard's comment view applies them:
     * admins read all, app managers all but admin-only, everyone else standard comments.
     *
     * @return list<string>
     */
    public function commentVisibilities(): array
    {
        return match ((string) ($this->userModel->getById($this->userId)['role'] ?? '')) {
            Role::APP_ADMIN => [Role::APP_USER, Role::APP_MANAGER, Role::APP_ADMIN],
            Role::APP_MANAGER => [Role::APP_USER, Role::APP_MANAGER],
            default => [Role::APP_USER],
        };
    }

    public function container(): Container
    {
        return $this->container;
    }
}
