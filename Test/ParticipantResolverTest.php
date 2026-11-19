<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;

class ParticipantResolverTest extends Base
{
    private const AGENT_TABLE = '\Kanboard\Plugin\Agents\Model\AgentTable';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../vendor-chatparts/autoload.php';
    }

    private function agentsTable(): void
    {
        $this->container['db']->getConnection()->exec(
            'CREATE TABLE IF NOT EXISTS agents (id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL, agent_user_id INTEGER NOT NULL UNIQUE, kind VARCHAR(20) NOT NULL, created_at INTEGER NOT NULL)'
        );
    }

    public function testAgentRosterUserIsAgent(): void
    {
        if (! class_exists(self::AGENT_TABLE)) {
            $this->markTestSkipped('Agents is not installed');
        }
        $owner = (int) $this->container['userModel']->create(['username' => 'ada', 'name' => 'Ada']);
        $bot = (int) $this->container['userModel']->create(['username' => 'ada-claude']);
        $resolver = new ParticipantResolver($this->container);

        $this->assertSame('human', $resolver->forUser($bot)->kind, 'no roster table yet: everyone is a person');

        $this->agentsTable();
        $class = self::AGENT_TABLE;
        (new $class($this->container))->adopt($owner, $bot, 'claude');

        $agent = $resolver->forUser($bot);
        $this->assertSame(['kind' => 'agent', 'id' => (string) $bot, 'name' => 'ada-claude'], $agent->toArray());
        $this->assertSame(['kind' => 'human', 'id' => (string) $owner, 'name' => 'Ada'], $resolver->forUser($owner)->toArray());
    }

    public function testWithoutAgentsPluginEveryoneIsHuman(): void
    {
        $this->agentsTable();
        $user = (int) $this->container['userModel']->create(['username' => 'bob']);
        $this->container['db']->table('agents')->insert(['owner_user_id' => 1, 'agent_user_id' => $user, 'kind' => 'codex', 'created_at' => 1]);

        $this->assertSame('human', (new ParticipantResolver($this->container, '\No\Such\Roster'))->forUser($user)->kind);
    }

    public function testBot(): void
    {
        $this->assertSame(['kind' => 'bot', 'id' => null, 'name' => 'project-bot'], (new ParticipantResolver($this->container))->bot()->toArray());
    }
}
