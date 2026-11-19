<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/ImageFixtures.php';

use KanboardTests\units\Base;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\ObjectStorage\ObjectStorageException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Controller\RoomController;
use Kanboard\Plugin\AgentChat\Model\FileModel;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Plugin;

/** Images posted with a message through the web composer (images[] in a multipart form). */
class SendImagesTest extends Base
{
    private string $storeDir;
    private ImageFixtures $img;
    private int $project;
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
        $this->storeDir = ImageFixtures::tempDir('agentchat-store-');
        mkdir($this->storeDir);
        $this->container['objectStorage'] = new FileStorage($this->storeDir);
        $this->img = new ImageFixtures(ImageFixtures::tempDir('agentchat-up-'));
        (new Plugin($this->container))->initialize();

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->member = (int) $this->container['userModel']->create(['username' => 'mia', 'name' => 'Mia']);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->member, Role::PROJECT_MEMBER);
        $this->container['userSession']->initialize($this->container['userModel']->getById($this->member));
        $this->container['memoryCache']->flush();
    }

    protected function tearDown(): void
    {
        ImageFixtures::remove($this->storeDir);
        ImageFixtures::remove($this->img->dir);
        parent::tearDown();
    }

    /** @return array{status: int, body: string} */
    private function send(string $message, array $uploads, string $clientMsgId = ''): array
    {
        $post = ['csrf_token' => $this->container['token']->getCSRFToken(), 'message' => $message] + ($clientMsgId === '' ? [] : ['client_msg_id' => $clientMsgId]);
        $files = $uploads === [] ? [] : ['images' => ImageFixtures::field(...$uploads)];
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST', 'HTTP_HX_REQUEST' => 'true'], ['project_id' => $this->project], $post, $files, []);
        $out = ['status' => 0, 'body' => ''];
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($data, $status = 200) use (&$out) {
            $out = ['status' => $status, 'body' => (string) $data];
        });
        $this->container['response'] = $resp;
        (new RoomController($this->container))->send();
        return $out;
    }

    private function messages(): array
    {
        return $this->container['db']->table(MessageModel::TABLE)->asc('id')->findAll();
    }

    private function files(): array
    {
        return $this->container['db']->table(FileModel::TABLE)->asc('id')->findAll();
    }

    private function blobCount(): int
    {
        return iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->storeDir, FilesystemIterator::SKIP_DOTS)));
    }

    public function testSendStoresTheImagesAndShowsThem(): void
    {
        $out = $this->send('look at these', [$this->img->upload(ImageFixtures::png(), 'one.png'), $this->img->upload(ImageFixtures::gif(), 'two.gif')]);

        $this->assertSame(200, $out['status']);
        [$msg] = $this->messages();
        $files = $this->files();
        $this->assertCount(2, $files);
        $this->assertSame([(int) $msg['id'], (int) $msg['id']], array_map('intval', array_column($files, 'message_id')));
        $this->assertSame(['image/png', 'image/gif'], array_column($files, 'mime'));
        $this->assertSame(4, $this->blobCount(), 'two images and two thumbnails');

        $parts = json_decode($msg['parts'], true);
        $this->assertSame(['text', 'file', 'file'], array_column($parts, 'type'));
        $this->assertSame('look at these', $msg['body_text']);
        foreach ($files as $i => $file) {
            $part = $parts[$i + 1];
            $this->assertSame($file['name'], $part['name']);
            $this->assertSame($file['mime'], $part['mime']);
            $this->assertMatchesRegularExpression('~^/(?!/)~', $part['url'], 'root-relative');
            $this->assertMatchesRegularExpression('~^/(?!/)~', $part['thumbUrl'], 'root-relative');
            $this->assertStringContainsString((string) $file['id'], $part['url']);
            $this->assertStringContainsString('src="'.htmlspecialchars($part['thumbUrl'], ENT_QUOTES).'"', $out['body']);
        }
    }

    public function testOneBadImageRefusesTheWholeSendAndStoresNothing(): void
    {
        $out = $this->send('hi', [$this->img->upload(ImageFixtures::png()), $this->img->upload(ImageFixtures::html(), 'evil.png')]);

        $this->assertSame(400, $out['status']);
        $this->assertStringContainsString('cp-notice', $out['body']);
        $this->assertSame([], $this->messages(), 'the message is not posted');
        $this->assertSame([], $this->files());
        $this->assertSame(0, $this->blobCount());
    }

    public function testMoreThanFourImagesIsRefused(): void
    {
        $out = $this->send('hi', array_map(fn () => $this->img->upload(ImageFixtures::png()), range(1, 5)));

        $this->assertSame(400, $out['status']);
        $this->assertStringContainsString('Up to 4 images per message.', $out['body']);
        $this->assertSame([], $this->messages());
    }

    public function testImagesAloneAreNotAMessage(): void
    {
        $out = $this->send('   ', [$this->img->upload(ImageFixtures::png())]);

        $this->assertSame(400, $out['status']);
        $this->assertSame([], $this->messages());
        $this->assertSame([], $this->files());
        $this->assertSame(0, $this->blobCount());
    }

    public function testARetryWithTheSameClientMsgIdStoresTheFilesOnce(): void
    {
        $a = $this->send('once', [$this->img->upload(ImageFixtures::png())], 'retry-1');
        $b = $this->send('once', [$this->img->upload(ImageFixtures::png())], 'retry-1');

        $this->assertCount(1, $this->messages());
        $this->assertCount(1, $this->files());
        $this->assertSame(2, $this->blobCount());
        $this->assertSame($a['body'], $b['body']);
    }

    public function testAFailedWriteLeavesNoMessageFileOrBlob(): void
    {
        // Puts the first image and its thumbnail, then fails the second image.
        $this->container['objectStorage'] = new class($this->storeDir) extends FileStorage {
            private int $puts = 0;

            public function put($key, &$blob)
            {
                if (++$this->puts === 3) {
                    throw new ObjectStorageException('disk full');
                }
                parent::put($key, $blob);
            }
        };

        $out = $this->send('two', [$this->img->upload(ImageFixtures::png()), $this->img->upload(ImageFixtures::png())], 'fail-1');

        $this->assertSame(500, $out['status']);
        $this->assertStringContainsString('cp-notice', $out['body']);
        $this->assertSame([], $this->messages(), 'the message is never written, so a retry starts clean');
        $this->assertSame([], $this->files());
        $this->assertSame(0, $this->blobCount());
    }

    public function testAFailedLinkSendsTheTextAloneWithAWarning(): void
    {
        if (DB_DRIVER !== 'sqlite') {
            $this->markTestSkipped('the failure is injected with a SQLite trigger');
        }
        // Every update of a file row is silently dropped: the link to the message never lands.
        $this->container['db']->getConnection()->exec('CREATE TRIGGER agentchat_files_frozen BEFORE UPDATE ON agentchat_files BEGIN SELECT RAISE(IGNORE); END');

        $out = $this->send('with a picture', [$this->img->upload(ImageFixtures::png())]);

        $this->assertSame(200, $out['status'], 'the message itself went out');
        $this->assertStringContainsString('cp-notice--warning', $out['body']);
        $this->assertStringContainsString('The images could not be attached', $out['body']);
        [$msg] = $this->messages();
        $this->assertSame(['text'], array_column(json_decode($msg['parts'], true), 'type'), 'no FilePart left pointing at a file nobody can read');
        $this->assertSame([], $this->files());
        $this->assertSame(0, $this->blobCount());
        $this->assertStringNotContainsString('cp-file', $out['body']);
    }
}
