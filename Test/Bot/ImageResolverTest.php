<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../ImageFixtures.php';
require_once dirname(__DIR__, 3).'/AiConnector/vendor/autoload.php';

use CarmeloSantana\PHPAgents\Contract\ImageResolverInterface;
use CarmeloSantana\PHPAgents\Image\ImageInputException;
use ChatParts\Model\Participant;
use ChatParts\Model\Part\TextPart;
use KanboardTests\units\Base;
use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Bot\StorageImageResolver;
use Kanboard\Plugin\AgentChat\Model\FileModel;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\RoomModel;

class ImageResolverTest extends Base
{
    private string $storeDir;
    private ImageFixtures $img;
    private int $project;
    private int $room;
    private int $asker;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../vendor-chatparts/autoload.php';
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $this->storeDir = ImageFixtures::tempDir('agentchat-store-');
        mkdir($this->storeDir);
        $this->container['objectStorage'] = new FileStorage($this->storeDir);
        $this->img = new ImageFixtures(ImageFixtures::tempDir('agentchat-up-'));

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->room = (int) (new RoomModel($this->container))->forProject($this->project)['id'];
        $this->asker = (int) $this->container['userModel']->create(['username' => 'ana']);
        $this->other = (int) $this->container['userModel']->create(['username' => 'bob']);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->asker, Role::PROJECT_MEMBER);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->other, Role::PROJECT_MEMBER);
    }

    protected function tearDown(): void
    {
        ImageFixtures::remove($this->storeDir);
        ImageFixtures::remove($this->img->dir);
        parent::tearDown();
    }

    private function file(int $roomId, int $userId, string $bytes, array $opts = []): int
    {
        $msg = (new MessageModel($this->container))->create($roomId, new Participant('human', (string) $userId, 'x'), [new TextPart('t', 'see')], $opts);
        return (new FileModel($this->container))->store($roomId, $msg, $userId, $this->img->upload($bytes, 'x.gif'));
    }

    private function resolver(?int $asker = null, ?int $room = null): StorageImageResolver
    {
        return new StorageImageResolver($this->container, $asker ?? $this->asker, $room ?? $this->room);
    }

    private function assertRefused(ImageResolverInterface $resolver, string $reference, string $why): void
    {
        try {
            $resolver->resolve($reference);
            $this->fail("resolved: $why");
        } catch (ImageInputException $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function testResolvesARoomImageWithItsSniffedType(): void
    {
        $bytes = ImageFixtures::png();
        $id = $this->file($this->room, $this->other, $bytes);

        $image = $this->resolver()->resolve('agentchat-file:'.$id);

        $this->assertSame('image/png', $image->mimeType);
        $this->assertSame($bytes, $image->bytes);
    }

    public function testRefusesForeignFile(): void
    {
        $secret = (int) $this->container['projectModel']->create(['name' => 'Secret']);
        $secretRoom = (int) (new RoomModel($this->container))->forProject($secret)['id'];
        $this->container['projectUserRoleModel']->addUser($secret, $this->other, Role::PROJECT_MEMBER);
        $foreign = $this->file($secretRoom, $this->other, ImageFixtures::png());
        $this->assertRefused($this->resolver(), 'agentchat-file:'.$foreign, 'a file of a project the asker cannot view');

        $this->container['projectUserRoleModel']->addUser($secret, $this->asker, Role::PROJECT_MEMBER);
        $this->assertRefused($this->resolver(), 'agentchat-file:'.$foreign, 'a file of another room, even one the asker can view');

        $task = (int) $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'T']);
        $private = $this->file($this->room, $this->other, ImageFixtures::png(), ['visibility' => 'private', 'private_to' => $this->other, 'task_id' => $task]);
        $this->assertRefused($this->resolver(), 'agentchat-file:'.$private, 'another person\'s private drawer image');

        $mine = $this->file($this->room, $this->asker, ImageFixtures::png());
        $outsider = (int) $this->container['userModel']->create(['username' => 'otto']);
        $this->assertRefused($this->resolver($outsider), 'agentchat-file:'.$mine, 'an asker who cannot view the project');

        foreach (['agentchat-file:999', 'agentchat-file:0', 'agentchat-file:'.$mine.' ', 'agentchat-file:1e1', '/etc/passwd', 'file:///etc/passwd', 'http://127.0.0.1/x.png', ''] as $ref) {
            $this->assertRefused($this->resolver(), $ref, "reference '$ref'");
        }
    }

    public function testRefusesStoredBytesThatAreNotAnAllowedImage(): void
    {
        $id = $this->file($this->room, $this->asker, ImageFixtures::png());
        $row = (new FileModel($this->container))->get($id);
        $html = ImageFixtures::html();
        $this->container['objectStorage']->put($row['storage_key'], $html);

        $this->assertRefused($this->resolver(), 'agentchat-file:'.$id, 'a blob swapped for HTML');
    }
}
