<?php
require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use ChatParts\Model\Part\TextPart;
use KanboardTests\units\Base;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Bot\AskerScope;
use Kanboard\Plugin\AgentChat\Bot\ChatAgent;
use Kanboard\Plugin\AgentChat\Bot\ContextBudget;
use Kanboard\Plugin\AgentChat\Bot\SourceRegistry;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AiConnector\Model\ModelCatalog;

class ContextBudgetTest extends Base
{
    private const KEYS = ['instructions', 'tools', 'task', 'history', 'sources_cap', 'message', 'reply_reserve'];

    private int $project;
    private int $room;
    private int $ana;
    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../vendor-chatparts/autoload.php';
        require_once dirname(__DIR__, 3).'/AiConnector/vendor/autoload.php';
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET', 'PHP_SELF' => '/index.php'], [], [], [], []);

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->room = (int) (new RoomModel($this->container))->forProject($this->project)['id'];
        $this->ana = (int) $this->container['userModel']->create(['username' => 'ana', 'name' => 'Ana']);
        $this->bob = (int) $this->container['userModel']->create(['username' => 'bob', 'name' => 'Bob']);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->ana, Role::PROJECT_MEMBER);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->bob, Role::PROJECT_MEMBER);
    }

    /** The default AiConnector profile, with its context window cached as $window (no discovery). */
    private function model(string $provider, string $model, ?int $window = null): void
    {
        $values = [
            'aiconnector_profiles' => json_encode([['id' => 'p1', 'label' => 'Test', 'provider' => $provider, 'model' => $model]]),
            'aiconnector_default' => 'p1',
        ];
        if ($window !== null) {
            $values[ModelCatalog::CACHE_KEY] = json_encode(["p1|$model" => ['context_window' => $window, 'source' => 'test', 'verified_at' => time()]]);
        }
        $this->container['configModel']->save($values);
        $this->container['memoryCache']->flush();
    }

    private function say(int $userId, string $text, array $opts = []): int
    {
        return (new MessageModel($this->container))->create($this->room, (new ParticipantResolver($this->container))->forUser($userId), [new TextPart('t1', $text)], $opts);
    }

    private function budget(): ContextBudget
    {
        return new ContextBudget($this->container);
    }

    /** @return array<string, int> key => tokens */
    private function tokensByKey(array $breakdown): array
    {
        return array_column($breakdown['segments'], 'tokens', 'key');
    }

    private function tools(?int $taskId = null): array
    {
        return ChatAgent::readTools(new AskerScope($this->container, $this->ana, $this->project, $taskId), new SourceRegistry());
    }

    public function testSegmentsInFillOrder(): void
    {
        $this->model('anthropic', 'claude-sonnet-5-5', 32768);
        $taskId = (int) $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'Fix the login page']);
        $drawer = ['visibility' => 'private', 'private_to' => $this->ana, 'task_id' => $taskId];
        $this->say($this->ana, 'an earlier drawer question', $drawer);

        $b = $this->budget()->breakdown($this->room, $this->ana, $taskId, 100, 2);

        $this->assertSame(32768, $b['window']);
        $this->assertSame(self::KEYS, array_column($b['segments'], 'key'));
        foreach ($b['segments'] as $s) {
            $this->assertIsString($s['label']);
            $this->assertNotSame('', $s['label']);
            $this->assertIsInt($s['tokens']);
        }
        $t = $this->tokensByKey($b);
        $this->assertSame(8000, $t['sources_cap']);
        $this->assertSame(4000, $t['reply_reserve']);
        $this->assertGreaterThan(0, $t['instructions']);
        $this->assertGreaterThan(0, $t['tools']);
        $this->assertGreaterThan(0, $t['task'], 'the drawer task is its own segment');
        $this->assertGreaterThan(0, $t['history']);
        $catalog = new ModelCatalog($this->container);
        $this->assertSame(2 * 1600 + $catalog->countTokens('Ana: '.str_repeat('x', 100), 'p1'), $t['message'], 'the draft is estimated from its length, with the author prefix the run adds; 1 600 per image');
    }

    public function testRoomHasNoTaskSegmentTokensAndNoDraftNoMessage(): void
    {
        $this->model('anthropic', 'claude-sonnet-5-5', 32768);

        $t = $this->tokensByKey($this->budget()->breakdown($this->room, $this->ana, null, 0, 0));

        $this->assertSame(0, $t['task']);
        $this->assertSame(0, $t['history']);
        $this->assertSame(0, $t['message']);
    }

    public function testImagesCountAtMostFour(): void
    {
        $this->model('anthropic', 'claude-sonnet-5-5', 32768);

        $t = $this->tokensByKey($this->budget()->breakdown($this->room, $this->ana, null, 0, 9));

        $this->assertSame(4 * 1600, $t['message']);
    }

    public function testHistoryTrimmedOldestFirstToFitWindow(): void
    {
        $this->model('anthropic', 'claude-sonnet-5-5', 8192);
        for ($i = 1; $i <= 45; $i++) {
            $this->say($i % 2 ? $this->bob : $this->ana, sprintf('message %02d ', $i).str_repeat('x', 1000));
        }

        $b = $this->budget()->breakdown($this->room, $this->ana, null, 200, 0);
        $t = $this->tokensByKey($b);
        $this->assertSame(2048, $t['sources_cap'], 'a quarter of an 8 192 window');
        $this->assertGreaterThan(0, $t['history'], 'an 8 192 window keeps some history');
        $this->assertLessThanOrEqual(8192, array_sum($t), 'everything fits the window');
        $one = $this->budget()->tokens('Bob: message 01 '.str_repeat('x', 1000), $this->project);
        $this->assertLessThan($one, 8192 - array_sum($t), 'one more message would not have fitted');

        $history = [];
        for ($i = 1; $i <= 39; $i++) {
            $history[] = new UserMessage(sprintf('Bob: message %02d ', $i).str_repeat('x', 1000));
        }
        $fit = $this->budget()->fit($this->project, $this->ana, null, $this->tools(), $history, 50);
        $kept = array_map(static fn ($m): string => substr((string) $m->content(), 0, 15), $fit['history']);
        $this->assertNotEmpty($kept);
        $this->assertSame('Bob: message 39', end($kept), 'the newest stays');
        $this->assertNotContains('Bob: message 01', $kept, 'the oldest go first');
        $this->assertSame(range(40 - count($kept), 39), array_map(static fn (string $s): int => (int) substr($s, 13), $kept), 'a contiguous newest run');
        $this->assertSame(2048, $fit['sources_cap']);
    }

    public function testSourcesCapIsAQuarterOfTheWindowAtMost8000(): void
    {
        foreach ([8192 => 2048, 16384 => 4096, 32000 => 8000, 262144 => 8000, 6000 => 1500] as $window => $cap) {
            $this->model('anthropic', 'claude-sonnet-5-5', $window);
            $this->assertSame($cap, $this->tokensByKey($this->budget()->breakdown($this->room, $this->ana, null, 0, 0))['sources_cap'], "window $window");
        }
    }

    public function testHistoryNeverOpensWithTheBotsTurn(): void
    {
        $this->model('anthropic', 'claude-sonnet-5-5', 200000);

        $fit = $this->budget()->fit($this->project, $this->ana, null, $this->tools(), [new AssistantMessage('old answer'), new UserMessage('Bob: hi')], 10);

        $this->assertCount(1, $fit['history']);
        $this->assertInstanceOf(UserMessage::class, $fit['history'][0]);
    }

    public function testMessageNeverTrimmed(): void
    {
        $this->model('anthropic', 'claude-sonnet-5-5', 2048);
        $this->say($this->bob, 'some history');
        $catalog = new ModelCatalog($this->container);

        $t = $this->tokensByKey($this->budget()->breakdown($this->room, $this->ana, null, 8000, 0));

        $this->assertSame($catalog->countTokens('Ana: '.str_repeat('x', 8000), 'p1'), $t['message'], 'the whole draft, over the window');
        $this->assertSame(0, $t['history'], 'history goes first');
        $this->assertGreaterThan(2048, array_sum($t));

        $fit = $this->budget()->fit($this->project, $this->ana, null, $this->tools(), [new UserMessage('Bob: some history')], 3000);
        $this->assertFalse($fit['fits'], 'a message that leaves no room for the reply is too long');
        $this->assertSame(3000, array_column($fit['segments'], 'tokens', 'key')['message']);

        $this->model('anthropic', 'claude-sonnet-5-5', 32768);
        $this->assertTrue($this->budget()->fit($this->project, $this->ana, null, $this->tools(), [], 3000)['fits']);
    }

    public function testUnknownModelFallsBackToCatalogDefault(): void
    {
        $this->model('openai', 'no-such-model-zz9');

        $b = $this->budget()->breakdown($this->room, $this->ana, null, 0, 0);

        $this->assertSame((new ModelCatalog($this->container))->getContextWindow('p1'), $b['window']);
        $this->assertGreaterThan(0, $b['window']);
    }

    public function testNoProfileUsesTheCatalogsGlobalDefault(): void
    {
        $b = $this->budget()->breakdown($this->room, $this->ana, null, 0, 0);

        $this->assertSame((new ModelCatalog($this->container))->getContextWindow(null), $b['window']);
    }
}
