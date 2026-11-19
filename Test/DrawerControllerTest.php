<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/Bot/FakeProvider.php';
require_once __DIR__.'/ImageFixtures.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Bot\BotRunner;
use Kanboard\Plugin\AgentChat\Controller\DrawerController;
use Kanboard\Plugin\AgentChat\Model\FileModel;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;
use Kanboard\Plugin\AgentChat\Plugin;

/** The task page's "Ask the bot" drawer: a private thread per user and task, its runs, and Share. */
class DrawerControllerTest extends Base
{
    private string $storeDir;
    private ImageFixtures $img;
    private int $project;
    private int $member;
    private int $other;
    private int $viewer;
    private int $outsider;
    private int $task;
    private int $otherTask;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\AgentChat\Schema\version_2($this->container['db']->getConnection());
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider());
        $this->storeDir = ImageFixtures::tempDir('agentchat-store-');
        mkdir($this->storeDir);
        $this->container['objectStorage'] = new FileStorage($this->storeDir);
        $this->img = new ImageFixtures(ImageFixtures::tempDir('agentchat-up-'));
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
        $this->outsider = (int) $this->container['userModel']->create(['username' => 'otto', 'name' => 'Otto']);
        $this->task = (int) $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'Fix the login page']);
        $this->otherTask = (int) $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'Write the docs']);
        $this->container['memoryCache']->flush();
    }

    protected function tearDown(): void
    {
        ImageFixtures::remove($this->storeDir);
        ImageFixtures::remove($this->img->dir);
        parent::tearDown();
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
    private function call(string $action, int $taskId, array $post = [], array $files = [], bool $htmx = true): array
    {
        $server = ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST'] + ($htmx ? ['HTTP_HX_REQUEST' => 'true'] : []);
        $this->container['request'] = new Request($this->container, $server, ['task_id' => $taskId], $post, $files, []);
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
        (new DrawerController($this->container))->$action();
        return $out;
    }

    /** Asks in the drawer; without htmx the answer is written before the redirect. */
    private function ask(int $taskId, string $text, array $uploads = [], bool $htmx = false): array
    {
        $files = $uploads === [] ? [] : ['images' => ImageFixtures::field(...$uploads)];
        return $this->call('send', $taskId, ['csrf_token' => $this->container['token']->getCSRFToken(), 'message' => $text], $files, $htmx);
    }

    private function messages(): array
    {
        return $this->container['db']->table(MessageModel::TABLE)->asc('id')->findAll();
    }

    private function files(): array
    {
        return $this->container['db']->table(FileModel::TABLE)->asc('id')->findAll();
    }

    private function room(): int
    {
        return (int) (new RoomModel($this->container))->forProject($this->project)['id'];
    }

    private function sidebar(): string
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['task_id' => $this->task], [], [], []);
        $task = $this->container['taskFinderModel']->getDetails($this->task);
        return $this->container['helper']->hook->render('template:task:sidebar:information', ['task' => $task]);
    }

    public function testThreadIsPerUserAndTask(): void
    {
        $this->container[BotRunner::PROVIDER_OVERRIDE] = new FakeProvider([[FakeProvider::text('Answer on login.')], [FakeProvider::text('Answer on docs.')]]);
        $this->loginAs($this->member);

        $out = $this->ask($this->task, 'what blocks the login fix?');
        $this->assertSame(302, $out['status']);
        $this->ask($this->otherTask, 'who writes the docs?');

        // No @bot needed: every drawer question gets a run, and everything stays private to its task.
        $rows = $this->messages();
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame('private', $row['visibility']);
            $this->assertSame($this->member, (int) $row['private_to']);
        }
        $this->assertSame([$this->task, $this->task, $this->otherTask, $this->otherTask], array_map('intval', array_column($rows, 'task_id')));
        $this->assertCount(2, $this->container['db']->table(RunModel::TABLE)->findAll());

        $shown = $this->call('show', $this->task);
        $this->assertSame(200, $shown['status']);
        $this->assertStringContainsString('class="cp-drawer"', $shown['body']);
        $this->assertStringContainsString('what blocks the login fix?', $shown['body']);
        $this->assertStringContainsString('Answer on login.', $shown['body']);
        $this->assertStringNotContainsString('who writes the docs?', $shown['body']);
        $this->assertStringNotContainsString('Answer on docs.', $shown['body']);

        // The room never shows a drawer message.
        $this->assertSame([], (new MessageModel($this->container))->listAfter($this->room(), $this->member, 0));
    }

    public function testOtherUserCannotOpenThread(): void
    {
        $this->container[BotRunner::PROVIDER_OVERRIDE] = new FakeProvider([[FakeProvider::text('Secret answer.')]]);
        $this->loginAs($this->member);
        $this->ask($this->task, 'my private question');

        // Another member opens their own, empty thread on the same task, and has nothing to share.
        $this->loginAs($this->other);
        $shown = $this->call('show', $this->task);
        $this->assertSame(200, $shown['status']);
        $this->assertStringNotContainsString('my private question', $shown['body']);
        $this->assertStringNotContainsString('Secret answer.', $shown['body']);
        $shared = $this->call('share', $this->task, ['csrf_token' => $this->container['token']->getCSRFToken()]);
        $this->assertSame(409, $shared['status']);
        $this->assertStringContainsString('cp-notice', $shared['body']);
        $this->assertCount(2, $this->messages(), 'nothing was posted');

        // Someone outside the project gets nothing at all.
        $this->loginAs($this->outsider);
        foreach (['show' => [], 'send' => ['message' => 'hi'], 'share' => []] as $action => $post) {
            try {
                $this->call($action, $this->task, $post + ($post === [] && $action === 'show' ? [] : ['csrf_token' => $this->container['token']->getCSRFToken()]));
                $this->fail("$action is refused to an outsider");
            } catch (AccessForbiddenException) {
            }
        }
        $this->assertCount(2, $this->messages());

        // A task that does not exist is refused like a foreign one.
        $this->loginAs($this->member);
        $this->expectException(AccessForbiddenException::class);
        $this->call('show', 9999);
    }

    public function testShareCopiesLatestPairToRoom(): void
    {
        $this->container[BotRunner::PROVIDER_OVERRIDE] = new FakeProvider([[FakeProvider::text('First answer.')], [FakeProvider::text('The **second** answer.')]]);
        $this->loginAs($this->member);
        $this->ask($this->task, 'first question');
        $this->ask($this->task, 'second question, see the screenshot', [$this->img->upload(ImageFixtures::png(), 'shot.png')]);
        [$original] = $this->files();
        $privateAnswer = $this->messages()[3];
        $this->assertSame('bot', $privateAnswer['sender_kind']);

        $out = $this->call('share', $this->task, ['csrf_token' => $this->container['token']->getCSRFToken()]);

        $this->assertSame(200, $out['status']);
        $room = (new MessageModel($this->container))->listAfter($this->room(), $this->other, 0);
        $this->assertCount(2, $room, 'the latest question and answer, nothing else');
        [$question, $answer] = $room;
        $this->assertSame(['human', $this->member], [$question['sender_kind'], (int) $question['sender_id']]);
        $this->assertSame('second question, see the screenshot', $question['body_text']);
        $this->assertSame('bot', $answer['sender_kind']);
        $this->assertSame('The **second** answer.', $answer['body_text']);
        $this->assertSame((int) $question['id'], (int) $answer['reply_to']);
        $this->assertSame(json_decode($privateAnswer['metadata'], true), json_decode($answer['metadata'], true), 'the bot copy keeps its metadata');
        $this->assertSame($privateAnswer['status'], $answer['status']);

        // The image is a new file with its own blobs, attached to the room copy.
        $files = $this->files();
        $this->assertCount(2, $files);
        $copy = $files[1];
        $this->assertSame([$this->room(), (int) $question['id']], [(int) $copy['room_id'], (int) $copy['message_id']]);
        $this->assertNotSame($original['storage_key'], $copy['storage_key']);
        $this->assertNotSame($original['thumb_key'], $copy['thumb_key']);
        $fileModel = new FileModel($this->container);
        $this->assertSame($fileModel->read($original, false), $fileModel->read($copy, false));
        $this->assertTrue($fileModel->canRead($this->other, $copy), 'room members can see the shared image');
        $this->assertFalse($fileModel->canRead($this->other, $original), 'the drawer image stays private');
        $parts = json_decode($question['parts'], true);
        $this->assertSame('f'.$copy['id'], $parts[1]['id']);

        // The private thread is untouched.
        $this->assertCount(4, array_filter($this->messages(), fn ($m) => $m['visibility'] === 'private'));

        // A share that cannot copy its images posts nothing.
        $this->container['objectStorage'] = new class($this->storeDir) extends FileStorage {
            public function put($key, &$blob)
            {
                throw new \Kanboard\Core\ObjectStorage\ObjectStorageException('disk full');
            }
        };
        $before = count($this->messages());
        $failed = $this->call('share', $this->task, ['csrf_token' => $this->container['token']->getCSRFToken()]);
        $this->assertSame(422, $failed['status']);
        $this->assertStringContainsString('cp-notice', $failed['body']);
        $this->assertCount($before, $this->messages());
        $this->assertCount(2, $this->files());
    }

    public function testButtonHiddenWhenGateClosed(): void
    {
        $this->loginAs($this->member);
        $this->assertStringContainsString('Ask the bot', $this->sidebar());
        $this->sidebar();
        $this->assertSame(['plugins/AgentChat/vendor-chatparts/assets/htmx.min.js', 'plugins/AgentChat/vendor-chatparts/assets/htmx-ext-sse.js', 'plugins/AgentChat/vendor-chatparts/assets/chatparts.js'], array_column($this->container['hook']->getListeners('template:layout:js'), 'template'), 'the button loads the kit on the task page, once');

        $this->loginAs($this->viewer);
        $this->assertStringNotContainsString('Ask the bot', $this->sidebar(), 'a viewer cannot ask');

        $this->loginAs($this->member);
        (new ProjectSettings($this->container, $this->project))->setBotEnabled(false);
        $this->assertStringNotContainsString('Ask the bot', $this->sidebar(), 'the project bot is off');

        (new ProjectSettings($this->container, $this->project))->setBotEnabled(true);
        $this->container['configModel']->save(['aiconnector_profiles' => '[]']);
        $this->container['memoryCache']->flush();
        $this->assertStringNotContainsString('Ask the bot', $this->sidebar(), 'AiConnector is not ready');
        try {
            $this->ask($this->task, 'anyone there?');
            $this->fail('the drawer send is refused while the gate is closed');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame([], $this->messages());
    }

    public function testDrawerRunScopesToTaskFirst(): void
    {
        $provider = new FakeProvider([[FakeProvider::text('It is blocked on review.')]]);
        $this->container[BotRunner::PROVIDER_OVERRIDE] = $provider;
        $this->loginAs($this->other);
        $this->container['configModel']->save([]);
        (new MessageModel($this->container))->create($this->room(), (new \Kanboard\Plugin\AgentChat\Model\ParticipantResolver($this->container))->forUser($this->other), [new \ChatParts\Model\Part\TextPart('t1', 'room chatter: ignore the task')]);
        $this->loginAs($this->member);

        $out = $this->ask($this->task, 'what is the status?', htmx: true);

        $this->assertSame(200, $out['status']);
        $this->assertMatchesRegularExpression('/sse-connect="[^"]*sid=[0-9a-f]{64}/', $out['body'], 'the answer streams to the drawer');
        $runs = $this->container['db']->table(RunModel::TABLE)->findAll();
        $this->assertCount(1, $runs);
        $draft = (new MessageModel($this->container))->getById((int) $runs[0]['message_id']);
        $this->assertSame(['private', $this->member, $this->task], [$draft['visibility'], (int) $draft['private_to'], (int) $draft['task_id']]);

        BotRunner::forContainer($this->container)->runToCompletion((int) $runs[0]['id']);
        $sent = $provider->sentText();
        $this->assertStringContainsString("Task #{$this->task}", $sent);
        $this->assertStringContainsString('Fix the login page', $sent);
        $this->assertStringContainsString('what is the status?', $sent);
        $this->assertStringNotContainsString('room chatter', $sent);
    }

    public function testAClientMsgIdNeverCrossesBetweenRoomAndDrawer(): void
    {
        $this->loginAs($this->member);
        $roomMessage = (new MessageModel($this->container))->create($this->room(), (new \Kanboard\Plugin\AgentChat\Model\ParticipantResolver($this->container))->forUser($this->member), [new \ChatParts\Model\Part\TextPart('t1', 'said in the room')], ['client_msg_id' => 'same-id']);

        $out = $this->call('send', $this->task, ['csrf_token' => $this->container['token']->getCSRFToken(), 'message' => 'said in the room', 'client_msg_id' => 'same-id']);

        $this->assertSame(400, $out['status']);
        $this->assertStringContainsString('cp-notice', $out['body']);
        $this->assertSame([$roomMessage], array_map('intval', array_column($this->messages(), 'id')), 'no draft, no run');
        $this->assertSame([], $this->container['db']->table(RunModel::TABLE)->findAll());
    }
}
