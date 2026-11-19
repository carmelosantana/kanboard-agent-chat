<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/Bot/FakeProvider.php';

use ChatParts\Model\MessageStatus;
use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Api\AgentChatRoomProcedure;
use Kanboard\Plugin\AgentChat\Bot\BotRunner;
use Kanboard\Plugin\AgentChat\Bot\DbCancellationToken;
use Kanboard\Plugin\AgentChat\Bot\Mention;
use Kanboard\Plugin\AgentChat\Controller\RoomController;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;
use Kanboard\Plugin\AgentChat\Plugin;

/** @bot from the web composer and from chatSendMessage; the stop endpoint. */
class SendBotTest extends Base
{
    private int $project;
    private int $member;
    private int $other;
    private int $viewer;
    private int $manager;

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
        ]);

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->member = $this->user('mia', Role::PROJECT_MEMBER);
        $this->other = $this->user('max', Role::PROJECT_MEMBER);
        $this->viewer = $this->user('vic', Role::PROJECT_VIEWER);
        $this->manager = $this->user('meg', Role::PROJECT_MANAGER);
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
    private function call(string $action, array $post, bool $htmx = true): array
    {
        $server = ['REQUEST_METHOD' => 'POST'] + ($htmx ? ['HTTP_HX_REQUEST' => 'true'] : []);
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

    private function send(string $text, bool $htmx = true): array
    {
        return $this->call('send', ['csrf_token' => $this->container['token']->getCSRFToken(), 'message' => $text], $htmx);
    }

    private function runs(): array
    {
        return $this->container['db']->table(RunModel::TABLE)->asc('id')->findAll();
    }

    private function messages(): array
    {
        return $this->container['db']->table(MessageModel::TABLE)->asc('id')->findAll();
    }

    private function room(): int
    {
        return (int) (new RoomModel($this->container))->forProject($this->project)['id'];
    }

    // ---- @bot detection ---------------------------------------------------------------------

    public function testMentionIsAStandaloneTokenOutsideCode(): void
    {
        foreach (['@bot hi', 'hey @BOT, what is left?', '(@bot)', "line\n@Bot.", '@bot'] as $text) {
            $this->assertTrue(Mention::ofBot($text), $text);
        }
        foreach (['@bots hi', 'mail@bot.com', '@botany', '@bot-ops', 'bot', '`@bot`', "```\n@bot\n```", 'see `x @bot y`', '#@bot'] as $text) {
            $this->assertFalse(Mention::ofBot($text), $text);
        }
        $this->assertTrue(Mention::ofBot('`code` then @bot'));
    }

    // ---- web send ---------------------------------------------------------------------------

    public function testMentionStartsRunWhenAllowed(): void
    {
        $this->loginAs($this->member);

        $out = $this->send('@bot what is left?');

        $this->assertSame(200, $out['status']);
        [$human, $draft] = $this->messages();
        $this->assertSame('human', $human['sender_kind']);
        $this->assertSame('bot', $draft['sender_kind']);
        $this->assertSame('submitted', $draft['status']);
        $runs = $this->runs();
        $this->assertCount(1, $runs);
        $this->assertSame($this->member, (int) $runs[0]['asked_by']);
        $this->assertSame((int) $draft['id'], (int) $runs[0]['message_id']);

        $this->assertStringContainsString('data-id="'.$human['id'].'"', $out['body']);
        $this->assertMatchesRegularExpression('/<article hx-ext="sse" sse-connect="[^"]*sid=([0-9a-f]{64})[^"]*" sse-close="done" sse-swap="delta,reasoning,parts,final" hx-swap="none" id="cp-msg-'.$draft['id'].'"/', $out['body']);
        preg_match('/sid=([0-9a-f]{64})/', $out['body'], $m);
        $this->assertSame($runs[0]['stream_token_hash'], hash('sha256', $m[1]));
        $this->assertSame(1, substr_count($out['body'], 'sse-connect='), 'one EventSource, on the bot bubble');
        $this->assertStringContainsString('id="cp-text-r'.$runs[0]['id'].'-draft-text"', $out['body']);
        $this->assertStringContainsString('data-state="streaming"', $out['body']);
    }

    public function testMessageWithoutMentionStartsNothing(): void
    {
        $this->loginAs($this->member);

        $out = $this->send('ask the bot later, `@bot` is in code');

        $this->assertSame(200, $out['status']);
        $this->assertCount(1, $this->messages());
        $this->assertSame([], $this->runs());
        $this->assertStringNotContainsString('sse-connect', $out['body']);
    }

    public function testMentionIgnoredWhenGateClosed(): void
    {
        $this->container['configModel']->save(['aiconnector_profiles' => '[]']);
        $this->loginAs($this->member);

        $out = $this->send('@bot hello?');

        $this->assertSame(200, $out['status']);
        $this->assertCount(1, $this->messages(), 'the message is stored');
        $this->assertSame([], $this->runs());
        $this->assertStringNotContainsString('sse-connect', $out['body']);
        $this->assertStringNotContainsString('cp-notice', $out['body'], 'no error');
    }

    public function testMentionIgnoredWhenTheProjectBotIsOff(): void
    {
        (new ProjectSettings($this->container, $this->project))->setBotEnabled(false);
        $this->loginAs($this->member);

        $this->assertSame(200, $this->send('@bot hello?')['status']);
        $this->assertCount(1, $this->messages());
        $this->assertSame([], $this->runs());
    }

    public function testMentionIgnoredForRoleOutsideAskRole(): void
    {
        $settings = new ProjectSettings($this->container, $this->project);
        $settings->setAskRole('managers');
        $this->loginAs($this->member);

        $out = $this->send('@bot hello?');

        $this->assertSame(200, $out['status']);
        $this->assertCount(1, $this->messages());
        $this->assertSame([], $this->runs());

        // With askRole=members (the default) a viewer may not post at all, so never asks.
        $settings->setAskRole('members');
        $this->loginAs($this->viewer);
        try {
            $this->send('@bot hello?');
            $this->fail('a viewer cannot post');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame([], $this->runs());
    }

    public function testLimitsHitReturnANoticeAndStoreTheMessage(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot first?');

        $out = $this->send('@bot second?');

        $this->assertSame(429, $out['status']);
        $this->assertStringContainsString('cp-notice', $out['body']);
        $this->assertStringContainsString('still answering your last question', $out['body']);
        $this->assertStringNotContainsString('sse-connect', $out['body']);
        $this->assertCount(1, $this->runs(), 'no second run');
        $bodies = array_column(array_filter($this->messages(), fn ($m) => $m['sender_kind'] === 'human'), 'body_text');
        $this->assertSame(['@bot first?', '@bot second?'], array_values($bodies), 'the human message is still stored');
    }

    public function testWithoutJsTheAnswerIsWrittenBeforeTheRedirect(): void
    {
        $this->container[BotRunner::PROVIDER_OVERRIDE] = new FakeProvider([[FakeProvider::text('Plain answer.')]]);
        $this->loginAs($this->member);

        $out = $this->send('@bot hello?', htmx: false);

        $this->assertSame(302, $out['status']);
        $this->assertSame('done', $this->runs()[0]['status']);
        $this->assertSame('Plain answer.', $this->messages()[1]['body_text']);
    }

    public function testSendAndPollReapStaleRuns(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot first?');
        $stale = time() - (int) (new LimitsSettings($this->container))->maxRunSeconds() - RunModel::GRACE_SECONDS - 1;
        $this->container['db']->table(RunModel::TABLE)->update(['started_at' => $stale]);

        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['project_id' => $this->project, 'after' => 0], [], [], []);
        $resp = $this->createMock(Response::class);
        $this->container['response'] = $resp;
        (new RoomController($this->container))->poll();

        $this->assertSame('error', $this->runs()[0]['status']);
    }

    // ---- stop -------------------------------------------------------------------------------

    public function testStopRequiresCsrf(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot hello?');

        foreach ([['csrf_token' => 'forged'], []] as $post) {
            try {
                $this->call('stop', $post);
                $this->fail('stop without a valid CSRF token must be refused');
            } catch (AccessForbiddenException) {
            }
        }
        $this->assertSame(0, (int) $this->runs()[0]['stop_requested']);
    }

    public function testStopIsAPost(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot hello?');
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['project_id' => $this->project, 'csrf_token' => $this->container['token']->getCSRFToken()], [], [], []);

        $this->expectException(AccessForbiddenException::class);
        (new RoomController($this->container))->stop();
    }

    public function testStopFlagsTheAskersRun(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot hello?');
        $runId = (int) $this->runs()[0]['id'];

        $out = $this->call('stop', ['csrf_token' => $this->container['token']->getCSRFToken(), 'run_id' => (string) $runId]);

        $this->assertSame(200, $out['status']);
        $this->assertSame(DbCancellationToken::STOPPED_BY_ASKER, (int) $this->runs()[0]['stop_requested']);
    }

    public function testStopWithoutARunIdStopsTheAskersActiveRunsInTheRoom(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot hello?');

        $this->assertSame(200, $this->call('stop', ['csrf_token' => $this->container['token']->getCSRFToken()])['status']);
        $this->assertSame(DbCancellationToken::STOPPED_BY_ASKER, (int) $this->runs()[0]['stop_requested']);
    }

    public function testStopByAnotherUserIsForbidden(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot hello?');
        $runId = (int) $this->runs()[0]['id'];
        $this->loginAs($this->other);

        try {
            $this->call('stop', ['csrf_token' => $this->container['token']->getCSRFToken(), 'run_id' => (string) $runId]);
            $this->fail('only the asker may stop a run');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame(0, (int) $this->runs()[0]['stop_requested']);
    }

    public function testStopOfARunInAnotherProjectIsForbidden(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot hello?');
        $runId = (int) $this->runs()[0]['id'];
        $otherProject = (int) $this->container['projectModel']->create(['name' => 'Other']);
        $this->container['projectUserRoleModel']->addUser($otherProject, $this->member, Role::PROJECT_MEMBER);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST', 'HTTP_HX_REQUEST' => 'true'], ['project_id' => $otherProject], ['csrf_token' => $this->container['token']->getCSRFToken(), 'run_id' => (string) $runId], [], []);

        $this->expectException(AccessForbiddenException::class);
        (new RoomController($this->container))->stop();
    }

    public function testStopOnAFinishedRunIsANoOp(): void
    {
        $this->loginAs($this->member);
        $this->send('@bot hello?');
        $this->container['db']->table(RunModel::TABLE)->update(['status' => 'done']);
        $runId = (int) $this->runs()[0]['id'];

        $out = $this->call('stop', ['csrf_token' => $this->container['token']->getCSRFToken(), 'run_id' => (string) $runId]);

        $this->assertSame(200, $out['status']);
        $this->assertSame(0, (int) $this->runs()[0]['stop_requested']);
    }

    // ---- JSON-RPC ---------------------------------------------------------------------------

    private function rpcSend(int $userId, string $body): array
    {
        $this->loginAs($userId);
        return (new AgentChatRoomProcedure($this->container))->chatSendMessage($this->project, $body);
    }

    public function testApiMentionIsAnsweredWithinTheRequest(): void
    {
        $provider = new FakeProvider([[FakeProvider::text('All done.')]]);
        $this->container[BotRunner::PROVIDER_OVERRIDE] = $provider;

        $result = $this->rpcSend($this->member, '@bot status?');

        $this->assertSame(['message_id', 'bot_message_id'], array_keys($result));
        $bot = (new MessageModel($this->container))->getById($result['bot_message_id']);
        $this->assertSame('bot', $bot['sender_kind']);
        $this->assertSame(MessageStatus::Done->value, $bot['status']);
        $this->assertSame('All done.', $bot['body_text']);
        $this->assertSame('done', $this->runs()[0]['status']);
        $this->assertCount(1, $provider->calls);
    }

    public function testApiMentionOverALimitStoresTheMessageAndSaysNoAnswerStarted(): void
    {
        $this->container['configModel']->save([LimitsSettings::QUESTIONS_PER_HOUR => '1']);
        $this->container[BotRunner::PROVIDER_OVERRIDE] = new FakeProvider([[FakeProvider::text('One.')]]);
        $this->rpcSend($this->member, '@bot one?');

        $result = $this->rpcSend($this->member, '@bot two?');

        $this->assertNull($result['bot_message_id']);
        $this->assertStringContainsString('hourly question limit for the bot (1)', $result['bot_error']);
        $this->assertSame('@bot two?', (new MessageModel($this->container))->getById($result['message_id'])['body_text']);
        $this->assertCount(1, $this->runs());
    }

    public function testApiMentionWithoutAskRightsOrGateIsJustAMessage(): void
    {
        (new ProjectSettings($this->container, $this->project))->setAskRole('managers');
        $this->assertSame(['message_id'], array_keys($this->rpcSend($this->member, '@bot hi')));

        $this->container['configModel']->save(['aiconnector_profiles' => '[]']);
        $this->assertSame(['message_id'], array_keys($this->rpcSend($this->manager, '@bot hi')));
        $this->assertSame([], $this->runs());
    }
}
