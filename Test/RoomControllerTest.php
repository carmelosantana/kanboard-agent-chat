<?php
require_once 'tests/units/Base.php';

use ChatParts\Model\MessageStatus;
use ChatParts\Model\Participant;
use ChatParts\Model\Part\TextPart;
use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Controller\RoomController;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Plugin;

class RoomControllerTest extends Base
{
    private int $projectA;
    private int $projectB;
    private int $member;
    private int $viewer;
    private int $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        (new Plugin($this->container))->initialize();

        $this->projectA = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->projectB = (int) $this->container['projectModel']->create(['name' => 'Secret']);
        $this->member = $this->user('mia', 'Mia Member', Role::PROJECT_MEMBER);
        $this->viewer = $this->user('vic', 'Vic Viewer', Role::PROJECT_VIEWER);
        $this->outsider = (int) $this->container['userModel']->create(['username' => 'otto']);
        $this->container['projectUserRoleModel']->addUser($this->projectB, $this->outsider, Role::PROJECT_MEMBER);
    }

    private function user(string $username, string $name, string $role): int
    {
        $id = (int) $this->container['userModel']->create(['username' => $username, 'name' => $name]);
        $this->container['projectUserRoleModel']->addUser($this->projectA, $id, $role);
        return $id;
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    /** @return array{status: int, body: string, redirect: ?string} */
    private function call(string $action, array $get, array $post = [], bool $htmx = true): array
    {
        $server = ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST'] + ($htmx ? ['HTTP_HX_REQUEST' => 'true'] : []);
        $this->container['request'] = new Request($this->container, $server, $get, $post, [], []);
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

    private function csrf(): string
    {
        return $this->container['token']->getCSRFToken();
    }

    private function room(int $projectId): int
    {
        return (int) (new RoomModel($this->container))->forProject($projectId)['id'];
    }

    private function post(int $roomId, int $userId, string $text, array $opts = []): int
    {
        return (new MessageModel($this->container))->create($roomId, new Participant('human', (string) $userId, 'x'), [new TextPart('t', $text)], $opts);
    }

    private function bot(): Participant
    {
        return (new ParticipantResolver($this->container))->bot();
    }

    private function rows(): array
    {
        return $this->container['db']->table(MessageModel::TABLE)->asc('id')->findAll();
    }

    // ---- poll -------------------------------------------------------------------------------

    public function testPollForeignRoomIsForbidden(): void
    {
        $this->post($this->room($this->projectB), $this->outsider, 'secret plans');
        $this->loginAs($this->member);

        $this->expectException(AccessForbiddenException::class);
        $this->call('poll', ['project_id' => $this->projectB, 'after' => 0]);
    }

    public function testPollRejectsARoomParamThatIsNotTheProjectsRoom(): void
    {
        $this->room($this->projectA);
        $roomB = $this->room($this->projectB);
        $this->loginAs($this->member);

        $this->expectException(AccessForbiddenException::class);
        $this->call('poll', ['project_id' => $this->projectA, 'room' => (string) $roomB, 'after' => 0]);
    }

    public function testPollDoesNotCreateTheRoom(): void
    {
        $this->loginAs($this->viewer);

        $this->assertSame(204, $this->call('poll', ['project_id' => $this->projectA, 'after' => 0])['status']);
        $this->assertSame(0, $this->container['db']->table(RoomModel::TABLE)->count(), 'a read endpoint writes nothing');
    }

    public function testArrayRoomParamIsRefusedNotAnError(): void
    {
        $this->room($this->projectA);
        $this->loginAs($this->member);
        foreach (['poll' => [[], ['after' => 0]], 'send' => [['csrf_token' => $this->csrf(), 'message' => 'hi', 'room' => ['1']], []]] as $action => [$post, $get]) {
            $get += ['project_id' => $this->projectA] + ($post === [] ? ['room' => ['1']] : []);
            try {
                $this->call($action, $get, $post);
                $this->fail("$action must refuse an array room param");
            } catch (AccessForbiddenException $e) {
            }
        }
        $this->assertSame([], $this->rows());
    }

    public function testRailEscapesHostileNames(): void
    {
        $this->container['userModel']->update(['id' => $this->member, 'name' => '<img src=x onerror=alert(1)>&']);
        $this->loginAs($this->viewer);

        $html = $this->call('show', ['project_id' => $this->projectA])['body'];

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;&amp;', $html);
    }

    public function testPollReturns204WhenNothingNew(): void
    {
        $id = $this->post($this->room($this->projectA), $this->member, 'hello');
        $this->loginAs($this->viewer);

        $this->assertSame(['status' => 204, 'body' => '', 'redirect' => null], $this->call('poll', ['project_id' => $this->projectA, 'after' => $id]));
    }

    public function testPollReturnsBubblesAfterTheCursorIncludingBotDrafts(): void
    {
        $room = $this->room($this->projectA);
        $first = $this->post($room, $this->member, 'first');
        $second = $this->post($room, $this->member, 'second <b>bold</b>');
        $messages = new MessageModel($this->container);
        $draft = $messages->create($room, $this->bot(), [], ['status' => MessageStatus::Streaming->value]);
        $submitted = $messages->create($room, $this->bot(), [], ['status' => MessageStatus::Submitted->value]);
        $answered = $messages->create($room, $this->bot(), [new TextPart('a', 'an answer')]);
        $this->post($room, $this->member, 'private', ['visibility' => 'private', 'private_to' => $this->member]);
        $this->loginAs($this->viewer);

        $out = $this->call('poll', ['project_id' => $this->projectA, 'after' => $first]);

        $this->assertSame(200, $out['status']);
        $this->assertStringNotContainsString('data-id="'.$first.'"', $out['body']);
        $this->assertStringContainsString('data-id="'.$second.'"', $out['body']);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $out['body']);
        // Other viewers see the bot "answering…": drafts come in their non-final state, and the
        // kit's cursor refetches them until they are final. Only the asker streams.
        $this->assertStringContainsString('data-id="'.$draft.'" data-state="streaming"', $out['body']);
        $this->assertStringContainsString('data-id="'.$submitted.'" data-state="submitted"', $out['body']);
        $this->assertStringContainsString('data-id="'.$answered.'"', $out['body']);
        $this->assertStringNotContainsString('sse-connect', $out['body']);
        $this->assertStringNotContainsString('>private<', $out['body']);
        $this->assertSame(4, substr_count($out['body'], '<article '));
    }

    public function testPollReturnsANewBotDraftWithoutAStream(): void
    {
        $room = $this->room($this->projectA);
        $id = $this->post($room, $this->member, 'q');
        $draft = (new MessageModel($this->container))->create($room, $this->bot(), [], ['status' => MessageStatus::Streaming->value]);
        $this->loginAs($this->viewer);

        $out = $this->call('poll', ['project_id' => $this->projectA, 'after' => $id]);

        $this->assertSame(200, $out['status']);
        $this->assertStringContainsString('data-id="'.$draft.'" data-state="streaming"', $out['body']);
        $this->assertStringNotContainsString('sse-connect', $out['body']);
    }

    // ---- send -------------------------------------------------------------------------------

    public function testSendRequiresCsrf(): void
    {
        $this->loginAs($this->member);

        try {
            $this->call('send', ['project_id' => $this->projectA], ['message' => 'hi', 'csrf_token' => 'forged']);
            $this->fail('a forged token must be refused');
        } catch (AccessForbiddenException $e) {
        }
        try {
            $this->call('send', ['project_id' => $this->projectA], ['message' => 'hi']);
            $this->fail('a missing token must be refused');
        } catch (AccessForbiddenException $e) {
        }
        $this->assertSame([], $this->rows());
    }

    public function testSendStoresAndRendersBubble(): void
    {
        $this->loginAs($this->member);

        $out = $this->call('send', ['project_id' => $this->projectA], [
            'csrf_token' => $this->csrf(),
            'message' => 'Hello <team> & #12',
            'client_msg_id' => 'abc123',
            // Server-side fields a client must not be able to set:
            'room_id' => '999', 'visibility' => 'private', 'private_to' => (string) $this->viewer, 'sender_kind' => 'bot', 'sender_id' => '1',
        ]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($this->room($this->projectA), (int) $row['room_id']);
        $this->assertSame('human', $row['sender_kind']);
        $this->assertSame($this->member, (int) $row['sender_id']);
        $this->assertSame('room', $row['visibility']);
        $this->assertNull($row['private_to']);
        $this->assertSame('abc123', $row['client_msg_id']);
        $this->assertSame('Hello <team> & #12', $row['body_text']);
        $parts = json_decode($row['parts'], true);
        $this->assertCount(1, $parts);
        $this->assertSame('text', $parts[0]['type']);

        $this->assertSame(200, $out['status']);
        $this->assertStringContainsString('data-id="'.$row['id'].'"', $out['body']);
        $this->assertStringContainsString('Mia Member', $out['body']);
        $this->assertStringContainsString('Hello &lt;team&gt; &amp;', $out['body']);
        $this->assertStringNotContainsString('<team>', $out['body']);
    }

    public function testSendIsIdempotentOnClientMsgId(): void
    {
        $this->loginAs($this->member);
        $post = ['csrf_token' => $this->csrf(), 'message' => 'once', 'client_msg_id' => 'same'];

        $a = $this->call('send', ['project_id' => $this->projectA], $post);
        $b = $this->call('send', ['project_id' => $this->projectA], $post);

        $this->assertCount(1, $this->rows());
        $this->assertSame($a['body'], $b['body']);
    }

    public function testSentTextPartsHaveDistinctIds(): void
    {
        // Response renders id="cp-text-{part id}"; two bubbles on one page must not share it.
        $this->loginAs($this->member);
        $a = $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf(), 'message' => 'one'])['body'];
        $b = $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf(), 'message' => 'two'])['body'];

        preg_match('~id="(cp-text-[^"]+)"~', $a, $ma);
        preg_match('~id="(cp-text-[^"]+)"~', $b, $mb);
        $this->assertNotEmpty($ma);
        $this->assertNotEmpty($mb);
        $this->assertNotSame($ma[1], $mb[1]);
    }

    public function testSendWithoutHtmxRedirectsToTheRoom(): void
    {
        $this->loginAs($this->member);

        $out = $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf(), 'message' => 'no js'], false);

        $this->assertSame(302, $out['status']);
        $this->assertMatchesRegularExpression('~project/'.$this->projectA.'/chat|action=show~', (string) $out['redirect']);
        $this->assertCount(1, $this->rows());
    }

    public function testSendByViewerOrOutsiderIsForbidden(): void
    {
        foreach ([$this->viewer, $this->outsider] as $userId) {
            $this->loginAs($userId);
            try {
                $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf(), 'message' => 'let me in']);
                $this->fail("user $userId must not post");
            } catch (AccessForbiddenException $e) {
            }
        }
        $this->assertSame([], $this->rows());
    }

    public function testSendRejectsARoomParamThatIsNotTheProjectsRoom(): void
    {
        $roomB = $this->room($this->projectB);
        $this->loginAs($this->member);

        $this->expectException(AccessForbiddenException::class);
        $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf(), 'message' => 'hi', 'room' => (string) $roomB]);
    }

    public function testSendValidationErrorIsAnInlineNotice(): void
    {
        $this->loginAs($this->member);

        $blank = $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf(), 'message' => "  \u{200B} "]);
        $this->assertSame(400, $blank['status']);
        $this->assertStringContainsString('cp-notice--error', $blank['body']);
        $this->assertStringContainsString('Write a message first.', $blank['body']);

        $badId = $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf(), 'message' => 'ok', 'client_msg_id' => '<x>']);
        $this->assertSame(400, $badId['status']);
        $this->assertStringContainsString('cp-notice--error', $badId['body']);
        $this->assertStringNotContainsString('<x>', $badId['body']);
        $this->assertStringContainsString('This message could not be sent.', $badId['body'], 'a translated fixed text, not the model\'s developer message');
        $this->assertStringNotContainsString('client_msg_id', $badId['body']);

        $missing = $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf()]);
        $this->assertSame(400, $missing['status']);

        $this->assertSame([], $this->rows());
    }

    public function testSendRejectsAnOversizedBodyBeforeTheModel(): void
    {
        $this->loginAs($this->member);

        $out = $this->call('send', ['project_id' => $this->projectA], ['csrf_token' => $this->csrf(), 'message' => str_repeat('a', 65537)]);

        $this->assertSame(400, $out['status']);
        $this->assertStringContainsString('cp-notice--error', $out['body']);
        $this->assertStringContainsString('too long', $out['body']);
        $this->assertSame([], $this->rows());
        // The room is not even created for a refused body.
        $this->assertSame(0, $this->container['db']->table(RoomModel::TABLE)->count());
    }

    // ---- page -------------------------------------------------------------------------------

    public function testMemberGetsComposerAndThread(): void
    {
        $room = $this->room($this->projectA);
        $id = $this->post($room, $this->member, 'earlier');
        $this->loginAs($this->member);

        $html = $this->call('show', ['project_id' => $this->projectA])['body'];

        $this->assertStringContainsString('id="cp-conversation"', $html);
        $this->assertStringContainsString('data-id="'.$id.'"', $html);
        $this->assertStringContainsString('data-cp-poll', $html);
        $this->assertMatchesRegularExpression('~hx-get="[^"]*after='.$id.'"~', $html);
        $this->assertStringContainsString('<form class="cp-box"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringNotContainsString('name="room"', $html, 'the room is the project\'s, never posted by the client');
        $this->assertStringNotContainsString('data-action="attach"', $html, 'no images yet');
        $this->assertStringNotContainsString('@bot', $html, 'no AiConnector in tests: the bot is not offered');
        $this->assertStringContainsString('cp-rail', $html);
        $this->assertStringContainsString('Mia Member', $html);
    }

    public function testViewerGetsNoComposer(): void
    {
        $this->loginAs($this->viewer);

        $html = $this->call('show', ['project_id' => $this->projectA])['body'];

        $this->assertStringContainsString('id="cp-conversation"', $html);
        $this->assertStringNotContainsString('cp-box', $html);
        $this->assertStringNotContainsString('name="message"', $html);
    }

    public function testShowForbidsOutsiders(): void
    {
        $this->loginAs($this->outsider);

        $this->expectException(AccessForbiddenException::class);
        $this->call('show', ['project_id' => $this->projectA]);
    }

    // ---- assets -----------------------------------------------------------------------------

    public function testChatAssetsLoadOnlyOnTheChatPage(): void
    {
        $hook = $this->container['hook'];
        $this->assertSame([], $hook->getListeners('template:layout:js'), 'initialize() registers no script for every page');
        $this->assertSame([], $hook->getListeners('template:layout:css'));
        $this->assertSame([], $hook->getListeners('template:layout:head'));

        $this->loginAs($this->member);
        $this->call('show', ['project_id' => $this->projectA]);

        $this->assertSame([
            'plugins/AgentChat/vendor-chatparts/assets/htmx.min.js',
            'plugins/AgentChat/vendor-chatparts/assets/htmx-ext-sse.js',
            'plugins/AgentChat/vendor-chatparts/assets/chatparts.js',
        ], array_column($hook->getListeners('template:layout:js'), 'template'));
        $this->assertSame(['plugins/AgentChat/vendor-chatparts/assets/chatparts.css', 'plugins/AgentChat/Assets/agentchat.css'], array_column($hook->getListeners('template:layout:css'), 'template'));
        $this->assertSame(['AgentChat:layout/htmx-meta'], array_column($hook->getListeners('template:layout:head'), 'template'));

        $meta = $this->container['template']->render('AgentChat:layout/htmx-meta');
        $this->assertSame('<meta name="htmx-config" content=\'{"allowEval":false,"allowScriptTags":false}\'>', trim($meta));
    }

    // ---- project header tab -----------------------------------------------------------------

    public function testChatTabShowsForProjectUsersOnly(): void
    {
        $project = $this->container['projectModel']->getById($this->projectA);
        $render = fn () => $this->container['helper']->hook->render('template:project-header:view-switcher', ['project' => $project, 'filters' => ['search' => '']]);

        $this->loginAs($this->viewer);
        $tab = $render();
        $this->assertStringContainsString('Chat', $tab);
        $this->assertMatchesRegularExpression('~href="[^"]*(project/'.$this->projectA.'/chat|RoomController)~', $tab);

        $this->loginAs($this->outsider);
        $this->assertStringNotContainsString('Chat', $render());
    }
}
