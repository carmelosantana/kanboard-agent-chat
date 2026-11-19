<?php

namespace Kanboard\Plugin\AgentChat\Model;

use InvalidArgumentException;
use Kanboard\Core\Base;
use RuntimeException;

/**
 * Chat rooms. v1 has one `project` room per project, created on first use; membership is
 * live from Kanboard's project permissions (see AccessPolicy), so there is no members table.
 */
class RoomModel extends Base
{
    public const TABLE = 'agentchat_rooms';

    /** The project's room, created on first use. Race-safe: a lost insert re-reads the winner's row. */
    public function forProject(int $projectId): array
    {
        $room = $this->findProjectRoom($projectId);
        if ($room !== null) {
            return $room;
        }

        $project = $this->projectModel->getById($projectId);
        if (empty($project)) {
            throw new InvalidArgumentException("No project #$projectId.");
        }

        // A concurrent request may insert first; the unique index agentchat_rooms_project_uidx
        // then rejects ours and the re-read below finds theirs.
        $this->db->table(self::TABLE)->insert([
            'kind' => 'project',
            'project_id' => $projectId,
            'name' => (string) $project['name'],
            'created_at' => time(),
        ]);

        $room = $this->findProjectRoom($projectId);
        if ($room === null) {
            throw new RuntimeException("Could not create the room for project #$projectId.");
        }
        return $room;
    }

    /** The project's room if it exists; never creates it (for read-only endpoints). */
    public function findForProject(int $projectId): ?array
    {
        return $this->findProjectRoom($projectId);
    }

    public function get(int $roomId): ?array
    {
        return $this->db->table(self::TABLE)->eq('id', $roomId)->findOne() ?: null;
    }

    protected function findProjectRoom(int $projectId): ?array
    {
        return $this->db->table(self::TABLE)->eq('kind', 'project')->eq('project_id', $projectId)->findOne() ?: null;
    }
}
