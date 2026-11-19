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
     * Comment visibilities the bot reads: standard comments only, whatever the asker's app role.
     * Answers are stored in the room or can be shared there, where Kanboard would never show
     * manager-only or admin-only comments to the other readers, so admins gain nothing here.
     *
     * @return list<string>
     */
    public function commentVisibilities(): array
    {
        return [Role::APP_USER];
    }

    public function container(): Container
    {
        return $this->container;
    }
}
