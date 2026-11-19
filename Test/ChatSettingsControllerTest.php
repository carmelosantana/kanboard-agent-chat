<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Controller\ChatSettingsController;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Plugin;

/** Project settings (managers) and the global run limits (app admin). */
class ChatSettingsControllerTest extends Base
{
    private int $project;
    private int $manager;
    private int $member;

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
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
                ['id' => 'p2', 'label' => 'Local', 'provider' => 'ollama', 'model' => 'qwen3'],
            ]),
            'aiconnector_key_p1' => 'sk-test-fake-key',
        ]);

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->manager = $this->user('mona', Role::PROJECT_MANAGER);
        $this->member = $this->user('mia', Role::PROJECT_MEMBER);
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

    /** @return array{status: int, body: string, redirect: ?string} */
    private function call(string $action, array $get = [], array $post = []): array
    {
        $server = ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST'];
        if ($post !== []) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        $this->container['request'] = new Request($this->container, $server, $get, $post, [], []);
        $out = ['status' => 0, 'body' => '', 'redirect' => null];
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($data, $status = 200) use (&$out) {
            $out['status'] = $status;
            $out['body'] = (string) $data;
        });
        $resp->method('redirect')->willReturnCallback(function ($url) use (&$out) {
            $out['status'] = 302;
            $out['redirect'] = $url;
        });
        $this->container['response'] = $resp;
        (new ChatSettingsController($this->container))->$action();
        $this->container['memoryCache']->flush();
        return $out;
    }

    private function settings(): ProjectSettings
    {
        return new ProjectSettings($this->container, $this->project);
    }

    public function testMemberCannotChangeProjectSettings(): void
    {
        $this->loginAs($this->member);
        try {
            $this->call('saveProject', ['project_id' => $this->project], ['ask_role' => 'everyone', 'ai_profile' => 'p2']);
            $this->fail('a member may not change the settings');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame('members', $this->settings()->askRole());
        $this->assertTrue($this->settings()->botEnabled());
        $this->assertNull($this->settings()->aiProfile());

        $this->expectException(AccessForbiddenException::class);
        $this->call('project', ['project_id' => $this->project]);
    }

    public function testManagerSavesProjectSettings(): void
    {
        $this->loginAs($this->manager);

        $page = $this->call('project', ['project_id' => $this->project]);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('name="ask_role"', $page['body']);
        $this->assertStringContainsString('value="p1"', $page['body']);
        $this->assertStringContainsString('value="p2"', $page['body']);
        $this->assertStringContainsString('name="bot_images"', $page['body']);

        // Unchecked boxes are absent from the post: both switch off.
        $out = $this->call('saveProject', ['project_id' => $this->project], ['ask_role' => 'managers', 'ai_profile' => 'p2']);

        $this->assertSame(302, $out['status']);
        $this->assertFalse($this->settings()->botEnabled());
        $this->assertFalse($this->settings()->botImages());
        $this->assertSame('managers', $this->settings()->askRole());
        $this->assertSame('p2', $this->settings()->aiProfile());

        $this->call('saveProject', ['project_id' => $this->project], ['bot_enabled' => '1', 'bot_images' => '1', 'ask_role' => 'everyone', 'ai_profile' => '']);
        $this->assertTrue($this->settings()->botEnabled());
        $this->assertTrue($this->settings()->botImages());
        $this->assertNull($this->settings()->aiProfile(), 'empty: AiConnector\'s default profile');
    }

    public function testInvalidProfileRejected(): void
    {
        $this->loginAs($this->manager);

        foreach ([['ai_profile' => 'nope'], ['ai_profile' => ['p1']], ['ask_role' => 'admins']] as $bad) {
            $out = $this->call('saveProject', ['project_id' => $this->project], $bad + ['bot_enabled' => '0', 'ask_role' => 'managers', 'ai_profile' => 'p1']);

            $this->assertSame(400, $out['status'], json_encode($bad));
            $this->assertStringContainsString('name="ask_role"', $out['body'], 'the form again, with the error');
        }
        $this->assertTrue($this->settings()->botEnabled(), 'nothing saved');
        $this->assertSame('members', $this->settings()->askRole());
        $this->assertNull($this->settings()->aiProfile());
    }

    public function testWithoutAiConnectorTheProfileFieldIsHidden(): void
    {
        $this->container[AiGate::CONNECTOR_OVERRIDE] = false;
        $this->loginAs($this->manager);

        $page = $this->call('project', ['project_id' => $this->project]);

        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('name="ai_profile"', $page['body']);
        $this->assertStringContainsString('The project bot is unavailable', $page['body']);
    }

    public function testLimitsMustBePositive(): void
    {
        $admin = (int) $this->container['userModel']->create(['username' => 'root', 'role' => Role::APP_ADMIN]);
        $this->loginAs($admin);
        $limits = new LimitsSettings($this->container);
        $valid = [];
        foreach (LimitsSettings::FIELDS as $key => $label) {
            $valid[$key] = '7';
        }

        $page = $this->call('limits');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Each streaming answer holds one PHP worker. Keep the instance limit below your PHP-FPM pm.max_children.', $page['body']);
        foreach (array_keys(LimitsSettings::FIELDS) as $key) {
            $this->assertStringContainsString('name="'.$key.'"', $page['body']);
        }

        foreach (['0', '-1', 'abc', '1.5', ''] as $bad) {
            $out = $this->call('saveLimits', [], [LimitsSettings::MAX_CONCURRENT_INSTANCE => $bad] + $valid);
            $this->assertSame(400, $out['status'], "'$bad'");
            $this->assertStringContainsString('must be a whole number of 1 or more', $out['body']);
        }
        $this->assertSame(4, $limits->maxConcurrentInstance(), 'nothing saved');
        $this->assertSame(6, $limits->maxIterations(), 'nothing saved, not even the valid ones');

        $out = $this->call('saveLimits', [], [LimitsSettings::MAX_CONCURRENT_INSTANCE => '3'] + $valid);
        $this->assertSame(302, $out['status']);
        $this->assertSame(3, $limits->maxConcurrentInstance(), 'read on the next request');
        $this->assertSame(7, $limits->questionsPerHour());
        $this->assertSame(7.0, $limits->maxRunSeconds());
    }

    public function testOnlyTheAppAdminChangesLimits(): void
    {
        $this->loginAs($this->manager);
        try {
            $this->call('saveLimits', [], [LimitsSettings::QUESTIONS_PER_HOUR => '99']);
            $this->fail('a project manager is not the app admin');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame(30, (new LimitsSettings($this->container))->questionsPerHour());
    }

    public function testSidebarsLinkTheSettings(): void
    {
        $this->loginAs($this->manager);
        $project = $this->container['projectModel']->getById($this->project);
        $this->assertStringContainsString('Chat', $this->container['helper']->hook->render('template:project:sidebar', ['project' => $project]));
        $this->assertStringContainsString('Chat limits', $this->container['helper']->hook->render('template:config:sidebar', []));
    }
}
