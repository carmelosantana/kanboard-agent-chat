<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Model\RoomModel;

class RoomModelTest extends Base
{
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $this->projectId = (int) $this->container['projectModel']->create(['name' => 'Website']);
    }

    public function testForProjectCreatesOnceThenReturnsTheSameRoom(): void
    {
        $rooms = new RoomModel($this->container);
        $room = $rooms->forProject($this->projectId);

        $this->assertSame('project', $room['kind']);
        $this->assertSame($this->projectId, (int) $room['project_id']);
        $this->assertSame('Website', $room['name']);
        $this->assertSame($room['id'], $rooms->forProject($this->projectId)['id']);
        $this->assertSame(1, $this->container['db']->table('agentchat_rooms')->count());
        $this->assertSame($room, $rooms->get((int) $room['id']));
    }

    public function testFindForProjectNeverCreates(): void
    {
        $rooms = new RoomModel($this->container);
        $this->assertNull($rooms->findForProject($this->projectId));
        $this->assertSame(0, $this->container['db']->table('agentchat_rooms')->count());

        $room = $rooms->forProject($this->projectId);
        $this->assertSame($room, $rooms->findForProject($this->projectId));
    }

    public function testGetReturnsNullForAnUnknownRoom(): void
    {
        $this->assertNull((new RoomModel($this->container))->get(999));
    }

    public function testForProjectRejectsAnUnknownProject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RoomModel($this->container))->forProject(999);
    }

    public function testForProjectRereadsWhenAConcurrentRequestCreatedTheRoomFirst(): void
    {
        // The first lookup misses; another request inserts the room before ours does.
        $db = $this->container['db'];
        $rooms = new class($this->container) extends RoomModel {
            public bool $raced = false;
            protected function findProjectRoom(int $projectId): ?array
            {
                if (! $this->raced) {
                    $this->raced = true;
                    $this->db->table(self::TABLE)->insert(['kind' => 'project', 'project_id' => $projectId, 'name' => 'Other', 'created_at' => 1]);
                    return null;
                }
                return parent::findProjectRoom($projectId);
            }
        };

        $room = $rooms->forProject($this->projectId);

        $this->assertSame('Other', $room['name']);
        $this->assertSame(1, $db->table('agentchat_rooms')->count());
    }
}
