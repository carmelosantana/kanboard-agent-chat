<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Controller\RoomController;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Plugin;
use Kanboard\Plugin\AiConnector\Model\ModelCatalog;

/** The composer's context ring: GET agentchat/:project_id/context and the page's ring button. */
class ContextEndpointTest extends Base
{
    private int $project;
    private int $member;
    private int $viewer;
    private int $outsider;

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
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]),
            'aiconnector_key_p1' => 'sk-test-fake-key',
            'aiconnector_default' => 'p1',
            ModelCatalog::CACHE_KEY => json_encode(['p1|claude-sonnet-5-5' => ['context_window' => 32000, 'source' => 'test', 'verified_at' => time()]]),
        ]);

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->member = $this->user('mia', Role::PROJECT_MEMBER);
        $this->viewer = $this->user('vic', Role::PROJECT_VIEWER);
        $this->outsider = (int) $this->container['userModel']->create(['username' => 'otto']);
        $this->container['memoryCache']->flush();
    }

    private function user(string $username, string $role): int
    {
        $id = (int) $this->container['userModel']->create(['username' => $username, 'name' => ucfirst($username)]);
        $this->container['projectUserRoleModel']->addUser($this->project, $id, $role);
        return $id;
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    /** @return array{status: int, body: string} */
    private function call(string $action, array $get, string $method = 'GET'): array
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => $method, 'HTTP_HX_REQUEST' => 'true'], ['project_id' => $this->project] + $get, [], [], []);
        $out = ['status' => 0, 'body' => ''];
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($data, $status = 200) use (&$out) {
            $out['status'] = $status;
            $out['body'] = (string) $data;
        });
        $resp->method('status')->willReturnCallback(function ($status) use (&$out) {
            $out['status'] = $status;
        });
        $this->container['response'] = $resp;
        (new RoomController($this->container))->$action();
        return $out;
    }

    /** @return array<string, string> data-key => tokens as shown */
    private function rows(string $html): array
    {
        preg_match_all('~<div class="cp-context__row" data-key="([a-z_]+)"><span class="cp-context__label">[^<]*</span><span class="cp-context__tokens">([0-9,]+)</span></div>~', $html, $m);
        return array_combine($m[1], $m[2]);
    }

    public function testRingWithBreakdownRows(): void
    {
        $this->loginAs($this->member);

        $out = $this->call('context', ['draft_len' => '400', 'images' => '2']);

        $this->assertSame(200, $out['status']);
        $this->assertStringContainsString('class="cp-ring"', $out['body']);
        $this->assertStringContainsString('id="cp-context-pop" hx-swap-oob="innerHTML"', $out['body'], 'rows go out of band into the popover');
        $rows = $this->rows($out['body']);
        $this->assertSame(['instructions', 'tools', 'sources_cap', 'message', 'reply_reserve'], array_keys($rows), 'empty segments (task, history) are left out');
        $this->assertSame('8,000', $rows['sources_cap']);
        $this->assertSame('4,000', $rows['reply_reserve']);
        $message = 2 * 1600 + (new ModelCatalog($this->container))->countTokens('Mia: '.str_repeat('x', 400), 'p1');
        $this->assertSame(number_format($message), $rows['message']);
        $this->assertStringContainsString('of 32,000 tokens', $out['body']);
    }

    public function testParamsAreClamped(): void
    {
        $this->loginAs($this->member);

        $rows = $this->rows($this->call('context', ['draft_len' => 'abc', 'images' => '99'])['body']);
        $this->assertSame('6,400', $rows['message'], 'a non-numeric draft length is 0; at most 4 images');

        $huge = $this->rows($this->call('context', ['draft_len' => '999999999', 'images' => '-3'])['body']);
        $this->assertSame(number_format((new ModelCatalog($this->container))->countTokens('Mia: '.str_repeat('x', 8000), 'p1')), $huge['message'], 'at most 8 000 characters; a negative count is 0');
    }

    public function testOnlyGet(): void
    {
        $this->loginAs($this->member);

        $out = $this->call('context', ['draft_len' => '10'], 'POST');

        $this->assertSame(405, $out['status']);
        $this->assertSame('', $out['body']);
    }

    public function testViewerGetsNoRing(): void
    {
        $this->loginAs($this->viewer);

        $out = $this->call('context', ['draft_len' => '10']);

        $this->assertSame(204, $out['status']);
        $this->assertSame('', $out['body']);
    }

    public function testNoRingWhenTheGateIsClosedOrTheBotOff(): void
    {
        $this->loginAs($this->member);
        (new ProjectSettings($this->container, $this->project))->setBotEnabled(false);
        $this->assertSame(204, $this->call('context', [])['status']);

        (new ProjectSettings($this->container, $this->project))->setBotEnabled(true);
        $this->container['configModel']->save(['aiconnector_profiles' => '[]']);
        $this->container['memoryCache']->flush();
        $this->assertSame(204, $this->call('context', [])['status']);
    }

    public function testOutsiderIsForbidden(): void
    {
        $this->loginAs($this->outsider);

        $this->expectException(AccessForbiddenException::class);
        $this->call('context', []);
    }

    public function testPageRingOnlyForThoseWhoMayAsk(): void
    {
        $this->loginAs($this->member);
        $html = $this->call('show', [])['body'];
        $this->assertMatchesRegularExpression('~<button type="button" class="cp-ctl cp-ctl--context" hx-get="[^"]*context~', $html);

        $this->container['configModel']->save(['aiconnector_profiles' => '[]']);
        $this->loginAs($this->member);
        $this->assertStringNotContainsString('cp-ctl--context', $this->call('show', [])['body'], 'no gate, no ring');
    }

    public function testDrawerRingCountsTheTaskAndRefusesAForeignOne(): void
    {
        $task = (int) $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'Fix the login page']);
        $elsewhere = (int) $this->container['projectModel']->create(['name' => 'Elsewhere']);
        $foreign = (int) $this->container['taskCreationModel']->create(['project_id' => $elsewhere, 'title' => 'Not yours']);
        $this->loginAs($this->member);

        $rows = $this->rows($this->call('context', ['task_id' => (string) $task, 'draft_len' => '10'])['body']);
        $this->assertArrayHasKey('task', $rows, 'the drawer task has its own segment');
        $this->assertGreaterThan(0, (int) str_replace(',', '', $rows['task']));

        foreach ([$foreign, 999999] as $taskId) {
            try {
                $this->call('context', ['task_id' => (string) $taskId]);
                $this->fail("task #$taskId is not this project's");
            } catch (AccessForbiddenException) {
            }
        }
    }
}
