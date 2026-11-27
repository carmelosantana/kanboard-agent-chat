<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/AgentsRosterFixture.php';
require_once __DIR__.'/Bot/FakeProvider.php';

use ChatParts\Model\Part\TextPart;
use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Bot\BotRunner;
use Kanboard\Plugin\AgentChat\Controller\RoomController;
use Kanboard\Plugin\AgentChat\Controller\StreamController;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;
use Kanboard\Plugin\AgentChat\Plugin;

/** The room without AiConnector, without Agents, and without JavaScript. */
class DegradeTest extends Base
{
    private const AGENT_TABLE = '\Kanboard\Plugin\Agents\Model\AgentTable';

    private int $project;
    private int $manager;
    private int $member;
    private int $task;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\AgentChat\Schema\version_2($this->container['db']->getConnection());
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider());
        (new Plugin($this->container))->initialize();
        require_once dirname(__DIR__, 2).'/AiConnector/vendor/autoload.php';
        // AiConnector is configured: only its absence (below) closes the gate.
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]),
            'aiconnector_default' => 'p1',
            'aiconnector_key_p1' => 'sk-test-fake-key',
        ]);

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->manager = $this->user('mona', 'Mona', Role::PROJECT_MANAGER);
        $this->member = $this->user('mia', 'Mia', Role::PROJECT_MEMBER);
        $this->task = (int) $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'Fix the login page']);
        $this->container['memoryCache']->flush();
    }

    private function user(string $username, string $name, string $role): int
    {
        $id = (int) $this->container['userModel']->create(['username' => $username, 'name' => $name]);
        $this->container['projectUserRoleModel']->addUser($this->project, $id, $role);
        return $id;
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    /** @return array{status: int, body: string, redirect: ?string} */
    private function room(string $action, array $post = [], bool $htmx = true): array
    {
        $server = ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST'] + ($htmx ? ['HTTP_HX_REQUEST' => 'true'] : []);
        if ($post !== []) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        $this->container['request'] = new Request($this->container, $server, ['project_id' => $this->project], $post, [], []);
        $out = ['status' => 0, 'body' => '', 'redirect' => null];
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($data, $status = 200) use (&$out) {
            $out['status'] = $status;
            $out['body'] = (string) $data;
        });
        $resp->method('status')->willReturnCallback(function ($status) use (&$out) {
            $out['status'] = $status;
        });
        $resp->method('redirect')->willReturnCallback(function ($url) use (&$out) {
            $out['status'] = 302;
            $out['redirect'] = $url;
        });
        $this->container['response'] = $resp;
        (new RoomController($this->container))->$action();
        return $out;
    }

    private function sidebar(): string
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['task_id' => $this->task], [], [], []);
        return $this->container['helper']->hook->render('template:task:sidebar:information', ['task' => $this->container['taskFinderModel']->getDetails($this->task)]);
    }

    private function rows(): array
    {
        return $this->container['db']->table(MessageModel::TABLE)->asc('id')->findAll();
    }

    private function flash(): array
    {
        return session_exists('flash') ? session_get('flash') : [];
    }

    public function testNoAiConnectorHidesBotUi(): void
    {
        $this->loginAs($this->member);
        $this->assertStringContainsString('@bot', $this->room('show')['body'], 'AiConnector configured: the bot is offered');
        $this->assertStringContainsString('Ask the bot', $this->sidebar());

        $this->container[AiGate::CONNECTOR_OVERRIDE] = false;

        $page = $this->room('show')['body'];
        $this->assertStringNotContainsString('@bot', $page, 'not in the placeholder nor the picker');
        $this->assertStringNotContainsString('cp-ctl--context', $page, 'no context ring');
        $this->assertStringNotContainsString('The project bot is unavailable', $page, 'the explanation is for managers');
        $this->assertStringNotContainsString('Ask the bot', $this->sidebar(), 'no drawer button');

        // A @bot message is just a message: no draft, no run.
        $out = $this->room('send', ['message' => '@bot hello?']);
        $this->assertSame(200, $out['status']);
        $this->assertCount(1, $this->rows());
        $this->assertSame(0, $this->container['db']->table(RunModel::TABLE)->count());

        $this->loginAs($this->manager);
        $this->assertStringContainsString('The project bot is unavailable', $this->room('show')['body']);

        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['sid' => str_repeat('a', 64)], [], [], []);
        $this->expectException(AccessForbiddenException::class);
        (new StreamController($this->container))->stream();
    }

    public function testRailListsTheProjectBotUnderBotsOnlyWhileTheGateIsOpen(): void
    {
        $this->loginAs($this->member);
        $rail = fn (): string => preg_match('~<aside class="cp-rail".*?</aside>~s', $this->room('show')['body'], $m) ? $m[0] : '';

        $this->assertMatchesRegularExpression('~<h3 class="cp-rail__heading">Bots <span class="cp-rail__count">1</span></h3>.*Project bot~s', $rail());

        $this->container[AiGate::CONNECTOR_OVERRIDE] = false;
        $this->assertStringNotContainsString('Bots', $rail());
        $this->assertStringNotContainsString('Project bot', $rail());
        $this->assertStringContainsString('Mia', $rail(), 'people stay in the rail');
    }

    public function testNoAgentsPluginMeansNoBadges(): void
    {
        if (! class_exists(self::AGENT_TABLE)) {
            $this->markTestSkipped('Agents is not installed');
        }
        AgentsRosterFixture::create($this->container['db']->getConnection());
        $agent = $this->user('mia-claude', 'mia-claude', Role::PROJECT_MEMBER);
        $class = self::AGENT_TABLE;
        (new $class($this->container))->adopt($this->member, $agent, 'claude');
        $roomId = (int) (new RoomModel($this->container))->forProject($this->project)['id'];
        $messages = new MessageModel($this->container);
        $this->loginAs($this->member);

        $messages->create($roomId, (new ParticipantResolver($this->container))->forUser($agent), [new TextPart('t1', 'build is green')]);
        $this->assertStringContainsString('cp-badge--agent', $this->room('show')['body'], 'with Agents: an Agent badge');

        $this->container[ParticipantResolver::ROSTER_OVERRIDE] = '\Kanboard\Plugin\Agents\Model\NotInstalled';
        $messages->create($roomId, (new ParticipantResolver($this->container))->forUser($agent), [new TextPart('t2', 'and deployed')]);
        $page = $this->room('show')['body'];

        $this->assertStringContainsString('and deployed', $page);
        $this->assertSame(1, substr_count($page, 'cp-badge--agent'), 'only the message stored while Agents was there');
    }

    public function testNoJsFormPostWorks(): void
    {
        $this->container[BotRunner::PROVIDER_OVERRIDE] = new FakeProvider([[FakeProvider::text('Two tasks are open.')]]);
        $this->loginAs($this->member);

        // A plain form post: the message is stored, the answer is written, then back to the room.
        $out = $this->room('send', ['message' => '@bot what is open?'], htmx: false);
        $this->assertSame(302, $out['status']);
        $this->assertMatchesRegularExpression('~project/'.$this->project.'/chat|action=show~', (string) $out['redirect']);
        $this->assertSame(['@bot what is open?', 'Two tasks are open.'], array_column($this->rows(), 'body_text'));

        // A refusal goes back to the room too, as a flash message, never a bare fragment.
        $out = $this->room('send', ['message' => '   '], htmx: false);
        $this->assertSame(302, $out['status']);
        $this->assertMatchesRegularExpression('~project/'.$this->project.'/chat|action=show~', (string) $out['redirect']);
        $this->assertSame('Write a message first.', $this->flash()['failure'] ?? null);
        $this->assertCount(2, $this->rows());

        // Over a limit: the message is stored and the limit is said on the room page.
        $this->container['configModel']->save([\Kanboard\Plugin\AgentChat\Model\LimitsSettings::QUESTIONS_PER_HOUR => '1']);
        $this->container['memoryCache']->flush();
        $out = $this->room('send', ['message' => '@bot and now?'], htmx: false);
        $this->assertSame(302, $out['status']);
        $this->assertCount(3, $this->rows());
        $this->assertNotEmpty($this->flash()['failure'] ?? '', 'the limit is shown after the redirect');
    }

    public function testBotImagesOffShowsTheHintInRoomAndDrawer(): void
    {
        $this->loginAs($this->member);
        $hint = "The bot won&#039;t see images";
        $drawer = function (): string {
            $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET', 'HTTP_HX_REQUEST' => 'true'], ['task_id' => $this->task], [], [], []);
            $body = '';
            $resp = $this->createMock(Response::class);
            $resp->method('html')->willReturnCallback(function ($data) use (&$body) {
                $body = (string) $data;
            });
            $this->container['response'] = $resp;
            (new \Kanboard\Plugin\AgentChat\Controller\DrawerController($this->container))->show();
            return $body;
        };
        $this->assertStringNotContainsString($hint, $this->room('show')['body']);
        $this->assertStringNotContainsString($hint, $drawer());

        (new ProjectSettings($this->container, $this->project))->setBotImages(false);

        $this->assertStringContainsString($hint, $this->room('show')['body'], 'the next request reads the setting');
        $this->assertStringContainsString($hint, $drawer());

        $this->container[AiGate::CONNECTOR_OVERRIDE] = false;
        $this->assertStringNotContainsString($hint, $this->room('show')['body'], 'no bot, nothing it would not see');
    }
}
