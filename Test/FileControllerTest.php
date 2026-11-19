<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/ImageFixtures.php';

use ChatParts\Model\Participant;
use ChatParts\Model\Part\TextPart;
use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Controller\FileController;
use Kanboard\Plugin\AgentChat\Model\FileModel;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Plugin;

class FileControllerTest extends Base
{
    private string $storeDir;
    private ImageFixtures $img;
    private int $project;
    private int $room;
    private int $member;
    private int $other;
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
        $this->storeDir = ImageFixtures::tempDir('agentchat-store-');
        mkdir($this->storeDir);
        $this->container['objectStorage'] = new FileStorage($this->storeDir);
        $this->img = new ImageFixtures(ImageFixtures::tempDir('agentchat-up-'));
        (new Plugin($this->container))->initialize();

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->room = (int) (new RoomModel($this->container))->forProject($this->project)['id'];
        $this->member = $this->user('mia', Role::PROJECT_MEMBER);
        $this->other = $this->user('max', Role::PROJECT_MEMBER);
        $this->viewer = $this->user('vic', Role::PROJECT_VIEWER);
        $this->outsider = (int) $this->container['userModel']->create(['username' => 'otto']);
    }

    protected function tearDown(): void
    {
        ImageFixtures::remove($this->storeDir);
        ImageFixtures::remove($this->img->dir);
        parent::tearDown();
    }

    private function user(string $username, string $role): int
    {
        $id = (int) $this->container['userModel']->create(['username' => $username]);
        $this->container['projectUserRoleModel']->addUser($this->project, $id, $role);
        return $id;
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function file(string $bytes, string $name = 'shot.png', array $opts = []): int
    {
        $msg = (new MessageModel($this->container))->create($this->room, new Participant('human', (string) $this->member, 'x'), [new TextPart('t', 'look')], $opts);
        return (new FileModel($this->container))->store($this->room, $msg, $this->member, $this->img->upload($bytes, $name));
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function call(string $action, int $id): array
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['id' => (string) $id], [], [], []);
        $resp = $this->getMockBuilder(Response::class)->setConstructorArgs([$this->container])->onlyMethods(['send'])->getMock();
        $this->container['response'] = $resp;
        (new FileController($this->container))->$action();
        $read = fn (string $p) => (new ReflectionProperty(Response::class, $p))->getValue($resp);
        return ['status' => $read('httpStatusCode'), 'headers' => $read('httpHeaders'), 'body' => (string) $read('httpBody')];
    }

    public function testMemberGetsTheImageWithLockedDownHeaders(): void
    {
        $bytes = ImageFixtures::png();
        $id = $this->file($bytes, "Ünïcode \"shot\";\r\nX: y.gif");
        $this->loginAs($this->member);

        $out = $this->call('show', $id);

        $this->assertSame(200, $out['status']);
        $this->assertSame($bytes, $out['body']);
        $this->assertSame('image/png', $out['headers']['Content-Type'], 'the sniffed type, not the name\'s');
        $this->assertSame('nosniff', $out['headers']['X-Content-Type-Options']);
        $this->assertSame("default-src 'none'; sandbox", $out['headers']['Content-Security-Policy']);
        $this->assertMatchesRegularExpression('~^private, max-age=\d+$~', $out['headers']['Cache-Control']);
        $this->assertMatchesRegularExpression('~^inline; filename="[A-Za-z0-9._-]+"$~', $out['headers']['Content-Disposition']);
    }

    public function testThumbIsAPng(): void
    {
        $id = $this->file(ImageFixtures::gif(), 'a.gif');
        $this->loginAs($this->viewer);

        $out = $this->call('thumb', $id);

        $this->assertSame(200, $out['status'], 'viewers see the room\'s images');
        $this->assertSame('image/png', $out['headers']['Content-Type']);
        $this->assertSame('image/png', (new finfo(FILEINFO_MIME_TYPE))->buffer($out['body']));
        $this->assertSame("default-src 'none'; sandbox", $out['headers']['Content-Security-Policy']);
        $this->assertSame('nosniff', $out['headers']['X-Content-Type-Options']);
    }

    public function testOutsiderGets403(): void
    {
        $id = $this->file(ImageFixtures::png());
        $this->loginAs($this->outsider);

        foreach (['show', 'thumb'] as $action) {
            try {
                $this->call($action, $id);
                $this->fail("$action served an outsider");
            } catch (AccessForbiddenException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPrivateMessageImagesAreTheOwnersOnly(): void
    {
        $task = (int) $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'T']);
        $id = $this->file(ImageFixtures::png(), 'p.png', ['visibility' => 'private', 'private_to' => $this->member, 'task_id' => $task]);

        $this->loginAs($this->member);
        $this->assertSame(200, $this->call('show', $id)['status']);

        $this->loginAs($this->other);
        $this->expectException(AccessForbiddenException::class);
        $this->call('show', $id);
    }

    public function testAFileNotYetGivenToAMessageIsNobodys(): void
    {
        $id = (new FileModel($this->container))->store($this->room, null, $this->member, $this->img->upload(ImageFixtures::png()));
        $this->loginAs($this->member);

        $this->expectException(AccessForbiddenException::class);
        $this->call('show', $id);
    }

    public function testFileResponsesDropTheSessionsNoCacheHeaders(): void
    {
        $id = $this->file(ImageFixtures::png());
        $this->loginAs($this->member);

        $out = $this->call('show', $id);

        $this->assertArrayNotHasKey('Pragma', $out['headers']);
        $this->assertArrayNotHasKey('Expires', $out['headers']);
        $this->assertSame(['Expires', 'Pragma'], FileController::DROPPED_HEADERS, 'PHP\'s session cache limiter sends these; the file response removes them');
    }

    public function testMissingFileIs404(): void
    {
        $this->loginAs($this->member);

        $this->assertSame(404, $this->call('show', 999)['status']);
        $this->assertSame(404, $this->call('thumb', 0)['status']);
    }

    public function testAFileWithoutAThumbnailHasNoThumb(): void
    {
        $id = $this->file(ImageFixtures::png());
        $this->container['db']->table(FileModel::TABLE)->eq('id', $id)->update(['thumb_key' => null]);
        $this->loginAs($this->member);

        $this->assertSame(404, $this->call('thumb', $id)['status']);
    }
}
