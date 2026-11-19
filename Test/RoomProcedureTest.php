<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/AgentsRosterFixture.php';

use ChatParts\Model\MessageStatus;
use ChatParts\Model\Part\TextPart;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\ResponseException;
use KanboardTests\units\Base;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Api\AgentChatRoomProcedure;
use Kanboard\Plugin\AgentChat\Controller\RoomController;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Plugin;

class RoomProcedureTest extends Base
{
    private const AGENT_TABLE = '\Kanboard\Plugin\Agents\Model\AgentTable';

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
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider());
        (new Plugin($this->container))->initialize();

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $users = $this->container['userModel'];
        $this->member = (int) $users->create(['username' => 'mia', 'name' => 'Mia <b>Member</b>']);
        $this->viewer = (int) $users->create(['username' => 'vic']);
        $this->outsider = (int) $users->create(['username' => 'otto']);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->member, Role::PROJECT_MEMBER);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->viewer, Role::PROJECT_VIEWER);
    }

    private function actAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function rpc(): AgentChatRoomProcedure
    {
        return new AgentChatRoomProcedure($this->container);
    }

    private function messageCount(): int
    {
        return (int) $this->container['db']->table(MessageModel::TABLE)->count();
    }

    private function roomCount(): int
    {
        return (int) $this->container['db']->table(RoomModel::TABLE)->count();
    }

    private function assertDenied(callable $fn, string $label = ''): void
    {
        try {
            $fn();
            $this->fail("Expected AccessDeniedException $label");
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertInvalid(callable $fn, string $label = ''): void
    {
        try {
            $fn();
            $this->fail("Expected InvalidArgumentException $label");
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), $label);
            $this->assertDoesNotMatchRegularExpression('/SQL|SELECT|INSERT|\.php|#\d+ /i', $e->getMessage(), $label);
        }
    }

    private function assertInternalError(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(-32603, $e->getCode());
        }
    }

    // ---- access -------------------------------------------------------------------------------

    public function testOutsiderTokenIsRefused(): void
    {
        $this->actAs($this->outsider);
        $this->assertDenied(fn () => $this->rpc()->chatSendMessage($this->project, 'hello'), 'outsider send');
        $this->assertDenied(fn () => $this->rpc()->chatRoomMessages($this->project), 'outsider read');
        // An unknown project looks the same as one the user cannot see.
        $this->assertDenied(fn () => $this->rpc()->chatRoomMessages(9999), 'unknown project');
        $this->assertSame(0, $this->messageCount());
        $this->assertSame(0, $this->roomCount());
    }

    public function testViewerReadsButCannotPost(): void
    {
        $this->actAs($this->viewer);
        $this->assertDenied(fn () => $this->rpc()->chatSendMessage($this->project, 'hello'));
        $this->assertSame([], $this->rpc()->chatRoomMessages($this->project));
        $this->assertSame(0, $this->messageCount());
    }

    public function testInactiveMemberIsRefused(): void
    {
        $this->actAs($this->member);
        $this->container['userModel']->disable($this->member);
        $this->container['memoryCache']->flush();
        $this->assertDenied(fn () => $this->rpc()->chatSendMessage($this->project, 'hello'));
        $this->assertDenied(fn () => $this->rpc()->chatRoomMessages($this->project));
    }

    public function testApplicationTokenIsRefused(): void
    {
        // No user session: Kanboard's application token. A post needs a sender; reads run as a user.
        $this->assertFalse($this->container['userSession']->isLogged());
        foreach ([fn () => $this->rpc()->chatSendMessage($this->project, 'hello'), fn () => $this->rpc()->chatRoomMessages($this->project)] as $i => $call) {
            try {
                $call();
                $this->fail("Call #$i: expected a refusal");
            } catch (ResponseException $e) {
                $this->assertSame([403, 'Forbidden', 'A user API token is required; the application token has no user.'], [$e->getCode(), $e->getMessage(), $e->getData()], "Call #$i");
            }
        }
        $this->assertSame(0, $this->messageCount());
    }

    // ---- send ---------------------------------------------------------------------------------

    public function testPostAppearsInRoomPoll(): void
    {
        $this->actAs($this->member);
        $result = $this->rpc()->chatSendMessage((string) $this->project, 'Deploy is green @bot');
        $this->assertSame(['message_id' => $result['message_id']], $result);
        $this->assertIsInt($result['message_id']);

        $row = (new MessageModel($this->container))->getById($result['message_id']);
        $this->assertSame('human', $row['sender_kind']);
        $this->assertSame($this->member, (int) $row['sender_id']);
        $this->assertSame('room', $row['visibility']);
        $this->assertSame((int) (new RoomModel($this->container))->findForProject($this->project)['id'], (int) $row['room_id']);
        $this->assertCount(1, json_decode($row['parts'], true));
        $this->assertSame('text', json_decode($row['parts'], true)[0]['type']);

        // The web poll shows it.
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET', 'HTTP_HX_REQUEST' => 'true'], ['project_id' => $this->project, 'after' => 0], [], [], []);
        $body = '';
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($data) use (&$body) {
            $body = (string) $data;
        });
        $this->container['response'] = $resp;
        (new RoomController($this->container))->poll();
        $this->assertStringContainsString('Deploy is green', $body);

        // And the API read shows it.
        $this->assertSame([[
            'id' => $result['message_id'],
            'sender_kind' => 'human',
            'sender_name' => 'Mia <b>Member</b>',
            'text' => 'Deploy is green @bot',
            'created_at' => (int) $row['created_at'],
        ]], $this->rpc()->chatRoomMessages($this->project));
    }

    public function testIdempotentClientMsgId(): void
    {
        $this->actAs($this->member);
        $first = $this->rpc()->chatSendMessage($this->project, 'once', 'job-42_a');
        $again = $this->rpc()->chatSendMessage($this->project, 'once', 'job-42_a');
        $this->assertSame($first, $again);
        $this->assertSame(1, $this->messageCount());
        $this->assertNotSame($first, $this->rpc()->chatSendMessage($this->project, 'twice', ''));
        $this->assertNotSame($first, $this->rpc()->chatSendMessage($this->project, 'thrice', null));
        $this->assertSame(3, $this->messageCount());
    }

    public function testAgentPostsAsAgent(): void
    {
        if (! class_exists(self::AGENT_TABLE)) {
            $this->markTestSkipped('Agents is not installed');
        }
        AgentsRosterFixture::create($this->container['db']->getConnection());
        $agent = (int) $this->container['userModel']->create(['username' => 'mia-claude']);
        $this->container['projectUserRoleModel']->addUser($this->project, $agent, Role::PROJECT_MEMBER);
        $class = self::AGENT_TABLE;
        (new $class($this->container))->adopt($this->member, $agent, 'claude');

        $this->actAs($agent);
        $id = $this->rpc()->chatSendMessage($this->project, 'build passed')['message_id'];
        $this->assertSame('agent', (new MessageModel($this->container))->getById($id)['sender_kind']);
        $this->assertSame('agent', $this->rpc()->chatRoomMessages($this->project)[0]['sender_kind']);
    }

    public function testInvalidSendsAreRejectedWithPlainMessages(): void
    {
        $this->actAs($this->member);
        $calls = [
            'bad project' => fn () => $this->rpc()->chatSendMessage('abc', 'hi'),
            'zero project' => fn () => $this->rpc()->chatSendMessage(0, 'hi'),
            'empty' => fn () => $this->rpc()->chatSendMessage($this->project, "  \u{200B} "),
            'too long' => fn () => $this->rpc()->chatSendMessage($this->project, str_repeat('a', 8001)),
            'NUL' => fn () => $this->rpc()->chatSendMessage($this->project, "a\0b"),
            'bad UTF-8' => fn () => $this->rpc()->chatSendMessage($this->project, "a\xC3\x28"),
            'not a string' => fn () => $this->rpc()->chatSendMessage($this->project, ['text' => 'hi']),
            'raw too big' => fn () => $this->rpc()->chatSendMessage($this->project, str_repeat(' ', 65537).'hi'),
            'bad client_msg_id' => fn () => $this->rpc()->chatSendMessage($this->project, 'hi', 'no spaces'),
            'array client_msg_id' => fn () => $this->rpc()->chatSendMessage($this->project, 'hi', ['x']),
        ];
        foreach ($calls as $label => $call) {
            $this->assertInvalid($call, $label);
        }
        $this->assertSame(0, $this->messageCount());
    }

    public function testRawBodyOverTheLimitNeverReachesTheModel(): void
    {
        $this->actAs($this->member);
        try {
            $this->rpc()->chatSendMessage($this->project, str_repeat('a', 65537));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This message is too long to send.', $e->getMessage());
        }
    }

    public function testSendStorageFailureIsAGenericInternalError(): void
    {
        $this->actAs($this->member);
        $pdo = $this->container['db']->getConnection();
        // Other AgentChat tables reference messages: MySQL needs its key checks off to drop it.
        if (DB_DRIVER === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        }
        $pdo->exec('DROP TABLE agentchat_messages'.(DB_DRIVER === 'postgres' ? ' CASCADE' : ''));
        if (DB_DRIVER === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->assertInternalError(fn () => $this->rpc()->chatSendMessage($this->project, 'hi'), 'The chat could not save this message.');
        $this->assertInternalError(fn () => $this->rpc()->chatRoomMessages($this->project), 'The chat could not be read.');
    }

    public function testDatabaseErrorDuringTheAccessCheckIsAGenericInternalError(): void
    {
        $this->actAs($this->member);
        $this->container['db']->getConnection()->exec('DROP TABLE project_has_users'.(DB_DRIVER === 'postgres' ? ' CASCADE' : ''));
        $this->assertInternalError(fn () => $this->rpc()->chatSendMessage($this->project, 'hi'), 'The chat could not save this message.');
        $this->assertInternalError(fn () => $this->rpc()->chatRoomMessages($this->project), 'The chat could not be read.');
    }

    // ---- read ---------------------------------------------------------------------------------

    public function testNoRoomYetReadsEmptyWithoutCreatingOne(): void
    {
        $this->actAs($this->member);
        $this->assertSame([], $this->rpc()->chatRoomMessages($this->project));
        $this->assertSame(0, $this->roomCount());
    }

    public function testReadIsRoomOnlyCursorPagedWithFinalBotMessages(): void
    {
        $roomId = (int) (new RoomModel($this->container))->forProject($this->project)['id'];
        $model = new MessageModel($this->container);
        $resolver = new ParticipantResolver($this->container);
        $mia = $resolver->forUser($this->member);
        $ids = [];
        $ids['first'] = $model->create($roomId, $mia, [new TextPart('a', '**bold** <i>x</i>')]);
        $ids['private'] = $model->create($roomId, $mia, [new TextPart('b', 'secret')], ['visibility' => 'private', 'private_to' => $this->member, 'task_id' => 1]);
        // A private bot draft (drawer answer) never holds the room back.
        $model->create($roomId, $resolver->bot(), [], ['status' => MessageStatus::Streaming->value, 'visibility' => 'private', 'private_to' => $this->member, 'task_id' => 1]);
        $ids['answer'] = $model->create($roomId, $resolver->bot(), [new TextPart('c', 'the answer')], ['status' => MessageStatus::Done->value]);
        $ids['stopped'] = $model->create($roomId, $resolver->bot(), [new TextPart('d', 'half')], ['status' => MessageStatus::Stopped->value]);
        $ids['failed'] = $model->create($roomId, $resolver->bot(), [], ['status' => MessageStatus::Error->value]);
        $ids['last'] = $model->create($roomId, $mia, [new TextPart('e', 'thanks')]);

        $this->actAs($this->viewer);
        $all = $this->rpc()->chatRoomMessages($this->project);
        $this->assertSame([$ids['first'], $ids['answer'], $ids['stopped'], $ids['failed'], $ids['last']], array_column($all, 'id'));
        $this->assertSame('**bold** <i>x</i>', $all[0]['text']);
        $this->assertSame(['bot', 'Project bot', 'the answer'], [$all[1]['sender_kind'], $all[1]['sender_name'], $all[1]['text']]);
        foreach ($all as $msg) {
            $this->assertSame(['id', 'sender_kind', 'sender_name', 'text', 'created_at'], array_keys($msg));
            $this->assertIsInt($msg['created_at']);
        }

        $this->assertSame([$ids['stopped'], $ids['failed']], array_column($this->rpc()->chatRoomMessages($this->project, $ids['answer'], 2), 'id'));
        $this->assertSame([$ids['last']], array_column($this->rpc()->chatRoomMessages($this->project, (string) $ids['failed'], '50'), 'id'));
        $this->assertSame([$ids['first']], array_column($this->rpc()->chatRoomMessages($this->project, 0, 0), 'id'), 'limit clamps up to 1');
        $this->assertSame([$ids['first']], array_column($this->rpc()->chatRoomMessages($this->project, null, -5), 'id'));
    }

    public function testUnfinishedBotDraftEndsThePageUntilItIsFinal(): void
    {
        $roomId = (int) (new RoomModel($this->container))->forProject($this->project)['id'];
        $model = new MessageModel($this->container);
        $resolver = new ParticipantResolver($this->container);
        $mia = $resolver->forUser($this->member);
        $before = $model->create($roomId, $mia, [new TextPart('a', '@bot status?')]);
        $draft = $model->create($roomId, $resolver->bot(), [], ['status' => MessageStatus::Submitted->value]);
        $later = $model->create($roomId, $mia, [new TextPart('b', 'also, hi')]);

        $this->actAs($this->member);
        $this->assertSame([$before], array_column($this->rpc()->chatRoomMessages($this->project), 'id'));
        $this->assertSame([], $this->rpc()->chatRoomMessages($this->project, $before), 'nothing past the draft yet');

        $model->update($draft, ['parts' => [new TextPart('c', 'partial')], 'status' => MessageStatus::Streaming->value]);
        $this->assertSame([], $this->rpc()->chatRoomMessages($this->project, $before), 'still streaming');

        $model->update($draft, ['parts' => [new TextPart('c', 'All green.')], 'status' => MessageStatus::Done->value]);
        $page = $this->rpc()->chatRoomMessages($this->project, $before);
        $this->assertSame([$draft, $later], array_column($page, 'id'));
        $this->assertSame('All green.', $page[0]['text']);
    }

    public function testReadLimitClampsTo100(): void
    {
        $roomId = (int) (new RoomModel($this->container))->forProject($this->project)['id'];
        $model = new MessageModel($this->container);
        $mia = (new ParticipantResolver($this->container))->forUser($this->member);
        for ($i = 0; $i < 105; $i++) {
            $model->create($roomId, $mia, [new TextPart('t', "m$i")]);
        }
        $this->actAs($this->member);
        $this->assertCount(100, $this->rpc()->chatRoomMessages($this->project, 0, 500));
        $this->assertCount(50, $this->rpc()->chatRoomMessages($this->project));
    }

    public function testInvalidReadParamsAreRejected(): void
    {
        $this->actAs($this->member);
        $this->assertInvalid(fn () => $this->rpc()->chatRoomMessages('x'), 'project');
        $this->assertInvalid(fn () => $this->rpc()->chatRoomMessages($this->project, 'abc'), 'after');
        $this->assertInvalid(fn () => $this->rpc()->chatRoomMessages($this->project, -1), 'negative after');
        $this->assertInvalid(fn () => $this->rpc()->chatRoomMessages($this->project, 0, 'ten'), 'limit');
        $this->assertInvalid(fn () => $this->rpc()->chatRoomMessages($this->project, 0, [1]), 'array limit');
    }

    // ---- surface ------------------------------------------------------------------------------

    public function testProcedureSurfaceHasOnlyTwoMethods(): void
    {
        $class = new ReflectionClass(AgentChatRoomProcedure::class);
        $own = array_values(array_map(fn ($m) => $m->getName(), array_filter($class->getMethods(), fn ($m) => $m->getDeclaringClass()->getName() === $class->getName())));
        sort($own);
        $this->assertSame(['chatRoomMessages', 'chatSendMessage'], $own);
        foreach ($own as $name) {
            foreach ($class->getMethod($name)->getParameters() as $param) {
                $this->assertFalse($param->hasType(), "$name(\${$param->getName()}) must not be typed");
            }
        }
        $this->assertSame(['project_id', 'body', 'client_msg_id'], array_map(fn ($p) => $p->getName(), $class->getMethod('chatSendMessage')->getParameters()));
        $this->assertSame(['project_id', 'after', 'limit'], array_map(fn ($p) => $p->getName(), $class->getMethod('chatRoomMessages')->getParameters()));
        foreach (glob('app/Api/Procedure/*Procedure.php') as $file) {
            $core = 'Kanboard\\Api\\Procedure\\'.basename($file, '.php');
            foreach ($own as $method) {
                $this->assertFalse(method_exists($core, $method), "$core::$method would win over the plugin");
            }
        }
    }

    public function testPluginRegistersTheProcedures(): void
    {
        $handler = $this->container['api']->getProcedureHandler();
        $this->actAs($this->member);
        $sent = $handler->executeProcedure('chatSendMessage', ['project_id' => $this->project, 'body' => 'via handler']);
        $read = $handler->executeProcedure('chatRoomMessages', ['project_id' => $this->project]);
        $this->assertSame($sent['message_id'], $read[0]['id']);
    }
}
