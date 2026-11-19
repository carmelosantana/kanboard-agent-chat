<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/ImageFixtures.php';

use ChatParts\Model\Participant;
use ChatParts\Model\Part\TextPart;
use KanboardTests\units\Base;
use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Model\FileModel;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Plugin;

class FileModelTest extends Base
{
    private string $storeDir;
    private ImageFixtures $img;
    private int $project;
    private int $room;
    private int $member;
    private int $message;

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
        $this->member = (int) $this->container['userModel']->create(['username' => 'mia']);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->member, Role::PROJECT_MEMBER);
        $this->message = $this->say($this->room, $this->member);
    }

    protected function tearDown(): void
    {
        ImageFixtures::remove($this->storeDir);
        ImageFixtures::remove($this->img->dir);
        parent::tearDown();
    }

    private function say(int $roomId, int $userId): int
    {
        return (new MessageModel($this->container))->create($roomId, new Participant('human', (string) $userId, 'x'), [new TextPart('t', 'look')]);
    }

    private function files(): FileModel
    {
        return new FileModel($this->container);
    }

    private function rows(): array
    {
        return $this->container['db']->table(FileModel::TABLE)->asc('id')->findAll();
    }

    /** Every blob in the store, as paths relative to it. */
    private function blobs(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->storeDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $out[] = substr($f->getPathname(), strlen($this->storeDir) + 1);
        }
        sort($out);
        return $out;
    }

    private function assertRefused(array $upload, string $why): void
    {
        try {
            $this->files()->store($this->room, $this->message, $this->member, $upload);
            $this->fail("stored: $why");
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame([], $this->rows(), "$why: no row");
        $this->assertSame([], $this->blobs(), "$why: no blob");
    }

    public function testStoresUnderARoomKeyWithAThumbnail(): void
    {
        $bytes = ImageFixtures::png(400, 300);
        $id = $this->files()->store($this->room, $this->message, $this->member, $this->img->upload($bytes, '../../etc/My Photo.png'));

        $row = $this->files()->get($id);
        $this->assertSame('image/png', $row['mime']);
        $this->assertSame(strlen($bytes), (int) $row['size']);
        $this->assertSame($this->message, (int) $row['message_id']);
        $this->assertSame($this->room, (int) $row['room_id']);
        $this->assertSame($this->member, (int) $row['uploaded_by']);
        $this->assertSame('My Photo.png', $row['name'], 'only the base name is kept');
        $this->assertMatchesRegularExpression('~^agentchat/'.$this->room.'/[0-9a-f]{32}$~', $row['storage_key'], 'never the client name in the path');
        $this->assertSame($row['storage_key'].'-thumb', $row['thumb_key']);

        $this->assertSame($bytes, $this->files()->read($row, false));
        $thumb = $this->files()->read($row, true);
        $this->assertSame('image/png', (new finfo(FILEINFO_MIME_TYPE))->buffer($thumb));
        $this->assertLessThanOrEqual(250, getimagesizefromstring($thumb)[0]);
    }

    public function testSniffsTheFourTypesFromTheirBytes(): void
    {
        $img = imagecreatetruecolor(30, 20);
        $encode = static function (callable $fn) use ($img): string {
            ob_start();
            $fn($img);
            return (string) ob_get_clean();
        };
        $cases = [
            'image/png' => ImageFixtures::png(30, 20),
            'image/gif' => $encode('imagegif'),
            'image/jpeg' => $encode('imagejpeg'),
            'image/webp' => $encode('imagewebp'),
        ];
        foreach ($cases as $mime => $bytes) {
            $this->assertSame(['mime' => $mime, 'width' => 30, 'height' => 20], FileModel::sniff($bytes), $mime);
        }
        foreach (['svg' => ImageFixtures::svg(), 'html' => ImageFixtures::html(), 'empty' => '', 'bmp' => $encode('imagebmp'), 'truncated png' => substr(ImageFixtures::png(), 0, 12)] as $what => $bytes) {
            $this->assertNull(FileModel::sniff($bytes), $what);
        }
    }

    public function testRejectsSvgAndOversize(): void
    {
        $this->assertRefused($this->img->upload(ImageFixtures::svg(), 'logo.svg'), 'svg');
        $this->assertRefused($this->img->upload(ImageFixtures::png().str_repeat("\0", FileModel::MAX_BYTES)), 'over 5 MB');
        $this->assertRefused($this->img->upload('', 'empty.png'), 'empty');
        $this->assertRefused($this->img->upload(ImageFixtures::png(), 'big.png', UPLOAD_ERR_INI_SIZE), 'upload error');
    }

    public function testMimeSniffedNotTrusted(): void
    {
        $id = $this->files()->store($this->room, $this->message, $this->member, $this->img->upload(ImageFixtures::png(), 'cat.gif'));
        $this->assertSame('image/png', $this->files()->get($id)['mime'], 'PNG bytes named .gif are a PNG');
        $this->files()->remove([$id]);

        $this->assertRefused($this->img->upload(ImageFixtures::html(), 'cat.png'), 'HTML bytes named .png');
    }

    public function testRejectsAnImageOverTheDecompressionGuard(): void
    {
        $this->assertRefused($this->img->upload(ImageFixtures::pngHeader(8000, 6000), 'bomb.png'), '48 MP');
        $this->assertSame([7000, 5000], array_slice(getimagesizefromstring(ImageFixtures::pngHeader(7000, 5000)), 0, 2), 'the fixture header is readable');
    }

    public function testCheckAllRefusesMoreThanFourBeforeLookingAtAny(): void
    {
        $uploads = array_map(fn () => $this->img->upload(ImageFixtures::png()), range(1, 5));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Up to 4 images per message.');
        $this->files()->checkAll($uploads);
    }

    public function testCheckAllRefusesTheWholeBatchForOneBadFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->files()->checkAll([$this->img->upload(ImageFixtures::png()), $this->img->upload(ImageFixtures::svg(), 'x.svg')]);
    }

    public function testUploadsReadsPhpsMultiFileShapeAndSkipsEmptyInputs(): void
    {
        $a = $this->img->upload(ImageFixtures::png(), 'a.png');
        $none = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];

        $this->assertSame([$a], FileModel::uploads(ImageFixtures::field($a, $none)));
        $this->assertSame([], FileModel::uploads(ImageFixtures::field($none)));
        $this->assertSame([], FileModel::uploads([]));
        $this->assertSame([$a], FileModel::uploads($a), 'a single (non-array) file input');
        $this->assertSame([], FileModel::uploads(['name' => ['x'], 'tmp_name' => 'not-a-list']), 'a malformed shape is no upload');
    }

    public function testRemoveDeletesRowsAndBlobs(): void
    {
        $id = $this->files()->store($this->room, $this->message, $this->member, $this->img->upload(ImageFixtures::png()));
        $this->assertCount(2, $this->blobs());

        $this->files()->remove([$id]);

        $this->assertSame([], $this->rows());
        $this->assertSame([], $this->blobs());
    }

    public function testProjectRemovalDeletesItsBlobsOnly(): void
    {
        $other = (int) $this->container['projectModel']->create(['name' => 'Other']);
        $otherRoom = (int) (new RoomModel($this->container))->forProject($other)['id'];
        $this->container['projectUserRoleModel']->addUser($other, $this->member, Role::PROJECT_MEMBER);
        $this->files()->store($this->room, $this->message, $this->member, $this->img->upload(ImageFixtures::png()));
        $keep = $this->files()->store($otherRoom, $this->say($otherRoom, $this->member), $this->member, $this->img->upload(ImageFixtures::gif(), 'a.gif'));
        $kept = $this->files()->get($keep);

        $this->assertTrue($this->container['projectModel']->remove($this->project));

        $this->assertSame([$kept['storage_key'], $kept['thumb_key']], $this->blobs(), 'only the other project\'s blobs are left');
        $this->assertSame([$keep], array_map('intval', array_column($this->rows(), 'id')));
    }
}
