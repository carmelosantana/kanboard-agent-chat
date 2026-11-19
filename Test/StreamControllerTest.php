<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/Bot/FakeProvider.php';

use ChatParts\Model\MessageStatus;
use ChatParts\Stream\SseWriter;
use KanboardTests\units\Base;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Bot\BotRunner;
use Kanboard\Plugin\AgentChat\Bot\DbCancellationToken;
use Kanboard\Plugin\AgentChat\Controller\StreamController;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;
use Kanboard\Plugin\AgentChat\Plugin;

class StreamControllerTest extends Base
{
    private const DONE_ONLY = "event: done\ndata: \n\n";

    private int $project;
    private int $room;
    private int $ana;
    private int $bob;

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
        $this->openGate();

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->room = (int) (new RoomModel($this->container))->forProject($this->project)['id'];
        $this->ana = $this->member('ana', 'Ana');
        $this->bob = $this->member('bob', 'Bob');
    }

    private function member(string $username, string $name): int
    {
        $id = (int) $this->container['userModel']->create(['username' => $username, 'name' => $name]);
        $this->container['projectUserRoleModel']->addUser($this->project, $id, Role::PROJECT_MEMBER);
        return $id;
    }

    private function openGate(): void
    {
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]),
            'aiconnector_key_p1' => 'sk-test-fake-key',
        ]);
        $this->container['memoryCache']->flush();
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    /** The asker's question and a pending run, as RoomController::send() leaves them. */
    private function ask(int $userId, string $question = '@bot hello?'): array
    {
        (new MessageModel($this->container))->create($this->room, (new \Kanboard\Plugin\AgentChat\Model\ParticipantResolver($this->container))->forUser($userId), [new \ChatParts\Model\Part\TextPart('t1', $question)]);
        return (new RunModel($this->container))->ask($this->room, $userId);
    }

    /**
     * Runs the stream action as the logged-in user and returns what it wrote.
     *
     * @return array{body: string, headers: list<string>, status: int}
     */
    private function stream(string $sid, ?FakeProvider $provider = null, bool $aborted = false): array
    {
        if ($provider !== null) {
            $this->container[BotRunner::PROVIDER_OVERRIDE] = $provider;
        }
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['sid' => $sid], [], [], []);
        $out = ['body' => '', 'headers' => [], 'status' => 0];
        $writer = new SseWriter(
            function (string $s) use (&$out): void { $out['body'] .= $s; },
            null,
            function (string $h, bool $replace, int $code) use (&$out): void {
                $out['headers'][] = $h;
                if ($code !== 0) {
                    $out['status'] = $code;
                }
            },
        );
        $controller = new class($this->container, $writer, $aborted) extends StreamController {
            public function __construct($c, private SseWriter $w, private bool $gone)
            {
                parent::__construct($c);
            }

            protected function writer(): SseWriter
            {
                return $this->w;
            }

            protected function connectionAborted(): bool
            {
                return $this->gone;
            }
        };
        $controller->stream();
        return $out;
    }

    private function runRow(int $runId): array
    {
        return $this->container['db']->table(RunModel::TABLE)->eq('id', $runId)->findOne();
    }

    /** @return list<string> the event names, in order */
    private function events(string $body): array
    {
        preg_match_all('/^event: (\S+)$/m', $body, $m);
        return $m[1];
    }

    public function testStreamsTheAnswerAndEndsWithFinalThenDone(): void
    {
        $turn = $this->ask($this->ana);
        $this->loginAs($this->ana);
        $provider = new FakeProvider([[FakeProvider::text('Hello '), FakeProvider::text('<world>')]]);

        $out = $this->stream($turn['sid'], $provider);

        $this->assertContains('Content-Type: text/event-stream', $out['headers']);
        $this->assertContains('X-Accel-Buffering: no', $out['headers']);
        $this->assertSame(['delta', 'delta', 'final', 'done'], $this->events($out['body']));
        $this->assertStringContainsString('hx-swap-oob="beforeend:#cp-text-r'.$turn['run_id'].'-draft-text"', $out['body']);
        $this->assertStringContainsString('&lt;world&gt;', $out['body'], 'deltas are escaped');
        $this->assertStringNotContainsString('<world>', $out['body']);
        $this->assertMatchesRegularExpression('/event: final\ndata: <article hx-swap-oob="true" id="cp-msg-'.$turn['message_id'].'"/', $out['body']);
        $this->assertStringEndsWith(self::DONE_ONLY, $out['body']);
        $this->assertSame('done', $this->runRow($turn['run_id'])['status']);
        $this->assertCount(1, $provider->calls);
    }

    public function testReusedSidReturnsDoneOnlyAndNoSecondRun(): void
    {
        $turn = $this->ask($this->ana);
        $this->loginAs($this->ana);
        $provider = new FakeProvider([[FakeProvider::text('Once.')], [FakeProvider::text('Twice?')]]);
        $this->stream($turn['sid'], $provider);

        $again = $this->stream($turn['sid'], $provider);

        $this->assertSame(self::DONE_ONLY, $again['body']);
        $this->assertSame(200, $again['status']);
        $this->assertContains('Content-Type: text/event-stream', $again['headers']);
        $this->assertCount(1, $provider->calls, 'the provider is asked once per run');
        $this->assertSame(1, $this->container['db']->table(RunModel::TABLE)->count());
        $this->assertSame('Once.', (new MessageModel($this->container))->getById($turn['message_id'])['body_text']);
    }

    public function testOtherUsersSidReturnsDoneOnly(): void
    {
        $turn = $this->ask($this->ana);
        $this->loginAs($this->bob);
        $provider = new FakeProvider([[FakeProvider::text('Secret.')]]);

        $out = $this->stream($turn['sid'], $provider);

        $this->assertSame(self::DONE_ONLY, $out['body']);
        $this->assertCount(0, $provider->calls);
        $this->assertSame('pending', $this->runRow($turn['run_id'])['status'], 'the asker can still claim it');
    }

    public function testUnknownOrMalformedSidReturnsDoneOnly(): void
    {
        $this->loginAs($this->ana);
        foreach ([str_repeat('a', 64), 'nope', ''] as $sid) {
            $this->assertSame(self::DONE_ONLY, $this->stream($sid)['body'], "sid '$sid'");
        }
    }

    public function testGateClosedEndsWithDoneAndFinalisesTheRun(): void
    {
        $turn = $this->ask($this->ana);
        $this->loginAs($this->ana);
        $this->container['configModel']->save(['aiconnector_profiles' => '[]']);
        $this->container['memoryCache']->flush();
        $provider = new FakeProvider([[FakeProvider::text('No.')]]);

        $out = $this->stream($turn['sid'], $provider);

        $this->assertSame(self::DONE_ONLY, $out['body']);
        $this->assertCount(0, $provider->calls);
        $this->assertSame('error', $this->runRow($turn['run_id'])['status'], 'no slot is held until the reaper');
        $this->assertSame('error', (new MessageModel($this->container))->getById($turn['message_id'])['status']);
    }

    public function testBotDisabledForTheProjectEndsWithDone(): void
    {
        $turn = $this->ask($this->ana);
        $this->loginAs($this->ana);
        (new \Kanboard\Plugin\AgentChat\Model\ProjectSettings($this->container, $this->project))->setBotEnabled(false);
        $provider = new FakeProvider([[FakeProvider::text('No.')]]);

        $this->assertSame(self::DONE_ONLY, $this->stream($turn['sid'], $provider)['body']);
        $this->assertCount(0, $provider->calls);
    }

    public function testADatabaseErrorStillEndsWithDone(): void
    {
        $this->loginAs($this->ana);
        $this->container['db']->getConnection()->exec('DROP TABLE agentchat_runs');

        $out = $this->stream(str_repeat('b', 64));

        $this->assertStringEndsWith(self::DONE_ONLY, $out['body']);
    }

    public function testAClientThatWentAwayStopsTheRun(): void
    {
        $turn = $this->ask($this->ana);
        $this->loginAs($this->ana);
        // The stop flag is read at most every 250 ms, so the model pauses after its first chunk.
        $provider = new FakeProvider([[FakeProvider::text('Partial '), static fn () => usleep(300_000), FakeProvider::text('more '), FakeProvider::text('rest')]]);

        $this->stream($turn['sid'], $provider, aborted: true);

        $run = $this->runRow($turn['run_id']);
        $this->assertSame(DbCancellationToken::STOPPED, (int) $run['stop_requested']);
        $this->assertSame('stopped', $run['status']);
        $message = (new MessageModel($this->container))->getById($turn['message_id']);
        $this->assertSame(MessageStatus::Stopped->value, $message['status']);
        $this->assertStringStartsWith('Partial', $message['body_text'], 'the partial reply is kept');
    }

    public function testToolRowsAndReasoningArriveAsOutOfBandParts(): void
    {
        $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'Fix the login page']);
        $turn = $this->ask($this->ana);
        $this->loginAs($this->ana);
        $provider = new FakeProvider([
            [FakeProvider::reasoning('Look it up.'), FakeProvider::tools(FakeProvider::call('c1', 'search_tasks', ['query' => 'login']))],
            [FakeProvider::reasoning(' Found.'), FakeProvider::text('See [1].')],
        ]);

        $out = $this->stream($turn['sid'], $provider);

        $this->assertSame(['reasoning', 'parts', 'parts', 'reasoning', 'delta', 'final', 'done'], $this->events($out['body']));
        $textId = '#cp-text-r'.$turn['run_id'].'-draft-text';
        $frames = explode("\n\n", $out['body']);
        $this->assertStringContainsString('hx-swap-oob="beforebegin:'.$textId.'"', $frames[0], 'the first reasoning chunk opens the reasoning part');
        $this->assertStringContainsString('class="cp-reasoning"', $frames[0]);
        $this->assertStringContainsString('hx-swap-oob="beforebegin:'.$textId.'"', $frames[1], 'the first tool call opens the tool strip');
        $this->assertStringContainsString('data-state="running"', $frames[1]);
        $this->assertStringContainsString('<details hx-swap-oob="true" id="cp-tools-'.$turn['message_id'].'"', $frames[2], 'later rows replace the strip');
        $this->assertStringContainsString('data-state="done"', $frames[2]);
        $this->assertStringContainsString('hx-swap-oob="beforeend:#cp-reasoning-r'.$turn['run_id'].'-draft-reasoning .cp-reasoning__text"', $frames[3]);
    }
}
