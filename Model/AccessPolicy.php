<?php

namespace Kanboard\Plugin\AgentChat\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

/**
 * Who may view, post in, ask the bot in, and manage a project's chat room.
 *
 * Membership is Kanboard's: the project role from projectUserRoleModel (direct or through a
 * group; custom project roles count as members, as Kanboard builds them on project-member)
 * and the app role. Unlike projectPermissionModel::isUserAllowed(), every check here is about
 * $userId, never the session user. Inactive users and missing projects get nothing.
 *
 *  - view:   any project role, or app admin
 *  - post:   member, manager, or app admin (viewers read only)
 *  - ask:    post rights and the project's ask role (managers | members | everyone);
 *            "everyone" means anyone who can view, viewers included
 *  - manage: project manager or app admin
 *  - ask the bot (canAskBot): post and ask rights while the project's AI gate is open
 */
class AccessPolicy extends Base
{
    public function canView(int $userId, int $projectId): bool
    {
        return $this->level($userId, $projectId) !== null;
    }

    public function canPost(int $userId, int $projectId): bool
    {
        return in_array($this->level($userId, $projectId), ['admin', Role::PROJECT_MANAGER, Role::PROJECT_MEMBER], true);
    }

    public function canAsk(int $userId, int $projectId): bool
    {
        $level = $this->level($userId, $projectId);
        if ($level === null) {
            return false;
        }

        return match ((new ProjectSettings($this->container, $projectId))->askRole()) {
            'managers' => in_array($level, ['admin', Role::PROJECT_MANAGER], true),
            'everyone' => true,
            default => in_array($level, ['admin', Role::PROJECT_MANAGER, Role::PROJECT_MEMBER], true),
        };
    }

    /** Whether $userId gets the bot here now: may post and ask, and the project's AI gate is open (asked at request time). */
    public function canAskBot(int $userId, int $projectId): bool
    {
        return $this->canPost($userId, $projectId) && $this->canAsk($userId, $projectId) && AiGate::isOpenFor($this->container, $projectId);
    }

    public function canManage(int $userId, int $projectId): bool
    {
        return in_array($this->level($userId, $projectId), ['admin', Role::PROJECT_MANAGER], true);
    }

    /** 'admin', a Role::PROJECT_* constant, or null for no access. */
    private function level(int $userId, int $projectId): ?string
    {
        $user = $this->userModel->getById($userId);
        if (empty($user) || (int) $user['is_active'] !== 1 || ! $this->projectModel->exists($projectId)) {
            return null;
        }
        if ($user['role'] === Role::APP_ADMIN) {
            return 'admin';
        }

        $role = (string) $this->projectUserRoleModel->getUserRole($projectId, $userId);
        if ($role === '') {
            return null;
        }
        return $this->role->isCustomProjectRole($role) ? Role::PROJECT_MEMBER : $role;
    }
}
