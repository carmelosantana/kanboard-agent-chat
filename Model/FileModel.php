<?php

namespace Kanboard\Plugin\AgentChat\Model;

use ChatParts\Model\Part\FilePart;
use InvalidArgumentException;
use Kanboard\Core\Base;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;
use Kanboard\Core\Thumbnail;
use Throwable;

/**
 * Images attached to chat messages: rows in agentchat_files, bytes in Kanboard's objectStorage.
 *
 * An upload is accepted only when its bytes sniff as a PNG, JPEG, WebP or GIF (sniff(); the
 * client's name and type are ignored, SVG and everything else is refused), at most MAX_BYTES and
 * at most MAX_PIXELS (a decompression guard, checked before GD decodes it).
 * The blob goes under agentchat/{room}/{random hex} and its PNG thumbnail (Kanboard's Thumbnail)
 * next to it with "-thumb"; the client's file name is kept only as the row's display name.
 *
 * Who may read a file is the reader's right to its message: the room's project (AccessPolicy::
 * canView) and, for a private message, being its owner.
 */
class FileModel extends Base
{
    public const TABLE = 'agentchat_files';
    public const MAX_FILES = 4;
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PIXELS = 40_000_000;
    public const MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** GD holds about this many bytes per pixel of the source image while thumbnailing. */
    private const GD_BYTES_PER_PIXEL = 5;

    /**
     * The uploads of a file input as a list of $_FILES entries, from PHP's `images[]` shape (one
     * list per key) or a single file; entries for "no file chosen" and malformed input are dropped.
     *
     * @return list<array{name: string, tmp_name: string, error: int, size: int}>
     */
    public static function uploads(array $field): array
    {
        if (! isset($field['tmp_name'])) {
            return [];
        }
        if (! is_array($field['tmp_name'])) {
            $field = array_map(static fn ($v) => [$v], $field);
        }
        $uploads = [];
        foreach ($field['tmp_name'] as $i => $tmp) {
            $error = $field['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if (! is_string($tmp) || ! is_int($error) || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $uploads[] = ['name' => is_string($field['name'][$i] ?? null) ? $field['name'][$i] : '', 'type' => is_string($field['type'][$i] ?? null) ? $field['type'][$i] : '', 'tmp_name' => $tmp, 'error' => $error, 'size' => (int) ($field['size'][$i] ?? 0)];
        }
        return $uploads;
    }

    /**
     * Checks a whole batch before anything is stored: at most MAX_FILES, each passing check().
     * Messages are plain text (e(), not t(): the Notice escapes them).
     *
     * @throws InvalidArgumentException with a message fit for the sender
     */
    public function checkAll(array $uploads): void
    {
        if (count($uploads) > self::MAX_FILES) {
            throw new InvalidArgumentException(t('Up to %d images per message.', self::MAX_FILES));
        }
        foreach ($uploads as $upload) {
            $this->check($upload);
        }
    }

    /**
     * The upload's bytes and sniffed MIME type, when it is an image this room accepts.
     *
     * @return array{bytes: string, mime: string, width: int, height: int}
     * @throws InvalidArgumentException with a message fit for the sender
     */
    public function check(array $upload): array
    {
        $name = $this->displayName((string) ($upload['name'] ?? ''));
        $path = $upload['tmp_name'] ?? null;
        if (($upload['error'] ?? null) === UPLOAD_ERR_INI_SIZE || ($upload['error'] ?? null) === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException(e('%s is larger than 5 MB.', $name));
        }
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || ! is_string($path) || $path === '' || ! is_file($path)) {
            throw new InvalidArgumentException(e('%s could not be uploaded.', $name));
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new InvalidArgumentException(e('%s is larger than 5 MB.', $name));
        }
        $bytes = (string) file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException(e('%s is larger than 5 MB.', $name));
        }
        $image = self::sniff($bytes);
        if ($image === null) {
            throw new InvalidArgumentException(e('%s is not a PNG, JPEG, WebP or GIF image.', $name));
        }
        if ($image['width'] * $image['height'] > self::MAX_PIXELS) {
            throw new InvalidArgumentException(e('%s has too many pixels (at most 40 megapixels).', $name));
        }
        return ['bytes' => $bytes] + $image;
    }

    /**
     * The MIME type and size of $bytes when they are one of MIMES, sniffed from the content:
     * getimagesize()'s signature and header parse, and finfo's verdict as well wherever the
     * fileinfo extension is loaded (the two must agree). Kanboard's stock Docker image ships
     * without fileinfo, so getimagesize() (ext/standard) is the check that is always there.
     *
     * @return ?array{mime: string, width: int, height: int}
     */
    public static function sniff(string $bytes): ?array
    {
        $size = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }
        $mime = image_type_to_mime_type($size[2]);
        if (! in_array($mime, self::MIMES, true)) {
            return null;
        }
        if (class_exists(\finfo::class) && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime) {
            return null;
        }
        return ['mime' => $mime, 'width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    /**
     * Stores one upload and returns the file id. Checks it first (check()); a failure after a
     * blob was written removes what this call wrote and rethrows. $messageId may be null while
     * the message is not written yet (linkToMessage() sets it); such a file is readable by no one.
     *
     * @throws InvalidArgumentException when the upload is refused
     */
    public function store(int $roomId, ?int $messageId, int $userId, array $upload): int
    {
        $image = $this->check($upload);
        $key = 'agentchat/'.$roomId.'/'.bin2hex(random_bytes(16));
        $written = [];
        try {
            $this->objectStorage->put($key, $image['bytes']);
            $written[] = $key;
            $thumbKey = $this->thumbnail($key, $image);
            if ($thumbKey !== null) {
                $written[] = $thumbKey;
            }
            $id = $this->db->table(self::TABLE)->persist([
                'message_id' => $messageId,
                'room_id' => $roomId,
                'uploaded_by' => $userId,
                'name' => $this->displayName((string) ($upload['name'] ?? '')),
                'mime' => $image['mime'],
                'size' => strlen($image['bytes']),
                'storage_key' => $key,
                'thumb_key' => $thumbKey,
                'created_at' => time(),
            ]);
            if (! $id) {
                throw new \RuntimeException("Could not store a file row in room #$roomId.");
            }
            return (int) $id;
        } catch (Throwable $e) {
            $this->removeBlobs($written);
            throw $e;
        }
    }

    /**
     * A copy of a stored file for $roomId, ahead of its message (message_id NULL, as store()):
     * a new row with its own blobs under new keys, never the original's keys, so removing
     * either never touches the other. A failure removes what this call wrote and rethrows.
     */
    public function copy(array $file, int $roomId): int
    {
        $key = 'agentchat/'.$roomId.'/'.bin2hex(random_bytes(16));
        $thumbKey = ($file['thumb_key'] ?? '') === '' ? null : $key.'-thumb';
        $written = [];
        try {
            $blob = $this->objectStorage->get((string) $file['storage_key']);
            $this->objectStorage->put($key, $blob);
            $written[] = $key;
            if ($thumbKey !== null) {
                $thumb = $this->objectStorage->get((string) $file['thumb_key']);
                $this->objectStorage->put($thumbKey, $thumb);
                $written[] = $thumbKey;
            }
            $id = $this->db->table(self::TABLE)->persist([
                'message_id' => null,
                'room_id' => $roomId,
                'uploaded_by' => $file['uploaded_by'],
                'name' => $file['name'],
                'mime' => $file['mime'],
                'size' => $file['size'],
                'storage_key' => $key,
                'thumb_key' => $thumbKey,
                'created_at' => time(),
            ]);
            if (! $id) {
                throw new \RuntimeException("Could not store a file row in room #$roomId.");
            }
            return (int) $id;
        } catch (Throwable $e) {
            $this->removeBlobs($written);
            throw $e;
        }
    }

    /** The message part for a stored file: root-relative URLs to FileController. */
    public function part(array $file): FilePart
    {
        $host = new KanboardHost($this->container);
        $id = (int) $file['id'];
        return new FilePart('f'.$id, (string) $file['name'], (string) $file['mime'], $host->url('file', ['id' => $id]), $file['thumb_key'] === null ? null : $host->url('thumb', ['id' => $id]));
    }

    /**
     * Gives files stored ahead of their message to it; true when every one now belongs to it.
     *
     * @param list<int> $ids
     */
    public function linkToMessage(array $ids, int $messageId): bool
    {
        if ($ids === []) {
            return true;
        }
        $this->db->table(self::TABLE)->in('id', $ids)->isNull('message_id')->update(['message_id' => $messageId]);
        return $this->db->table(self::TABLE)->in('id', $ids)->eq('message_id', $messageId)->count() === count($ids);
    }

    public function get(int $id): ?array
    {
        return $id < 1 ? null : ($this->db->table(self::TABLE)->eq('id', $id)->findOne() ?: null);
    }

    /** @return list<array> oldest first */
    public function forMessage(int $messageId): array
    {
        return $this->db->table(self::TABLE)->eq('message_id', $messageId)->asc('id')->findAll();
    }

    /** Whether $userId may see $file: they can view the room's project, and own it when its message is private. */
    public function canRead(int $userId, array $file): bool
    {
        $message = (new MessageModel($this->container))->getById((int) $file['message_id']);
        $room = (new RoomModel($this->container))->get((int) $file['room_id']);
        if ($message === null || $room === null || (int) $message['room_id'] !== (int) $room['id'] || empty($room['project_id'])) {
            return false;
        }
        if ($message['visibility'] !== 'room' && (int) $message['private_to'] !== $userId) {
            return false;
        }
        return (new AccessPolicy($this->container))->canView($userId, (int) $room['project_id']);
    }

    /**
     * The file's bytes, or its thumbnail's; null when it has none or the blob is gone.
     */
    public function read(array $file, bool $thumb): ?string
    {
        $key = $thumb ? $file['thumb_key'] : $file['storage_key'];
        if ($key === null || $key === '') {
            return null;
        }
        try {
            $bytes = $this->objectStorage->get($key);
        } catch (Throwable $e) {
            return null;
        }
        return is_string($bytes) ? $bytes : null;
    }

    /** Removes files by id: their blobs, then their rows. @param list<int> $ids */
    public function remove(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $rows = $this->db->table(self::TABLE)->in('id', $ids)->findAll();
        $this->removeBlobs($this->keysOf($rows));
        $this->db->table(self::TABLE)->in('id', $ids)->remove();
    }

    /** Every blob key (images and thumbnails) of the project's rooms. @return list<string> */
    public function keysForProject(int $projectId): array
    {
        $rooms = $this->db->table(RoomModel::TABLE)->eq('project_id', $projectId)->findAllByColumn('id');
        return $rooms === [] ? [] : $this->keysOf($this->db->table(self::TABLE)->in('room_id', $rooms)->findAll());
    }

    /** Removes blobs, logging (not throwing) for any that cannot be removed. @param list<string> $keys */
    public function removeBlobs(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->objectStorage->remove($key);
            } catch (Throwable $e) {
                $this->logger->error("AgentChat: could not remove blob $key: ".$e->getMessage());
            }
        }
    }

    /** @return list<string> */
    private function keysOf(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            foreach (['storage_key', 'thumb_key'] as $col) {
                if (($row[$col] ?? '') !== '') {
                    $keys[] = (string) $row[$col];
                }
            }
        }
        return $keys;
    }

    /**
     * Writes the PNG thumbnail next to $key and returns its key; null (the room then shows the
     * file name) when GD cannot decode the image or decoding it would not fit in memory.
     */
    private function thumbnail(string $key, array $image): ?string
    {
        if (! $this->decodeFits($image['width'] * $image['height'])) {
            return null;
        }
        try {
            $blob = Thumbnail::createFromString($image['bytes'])->resize()->toString();
        } catch (Throwable $e) {
            $this->logger->warning('AgentChat: no thumbnail for '.$image['mime'].': '.$e->getMessage());
            return null;
        }
        if ($blob === '' || $blob === false) {
            return null;
        }
        $thumbKey = $key.'-thumb';
        $this->objectStorage->put($thumbKey, $blob);
        return $thumbKey;
    }

    /** Whether GD can decode $pixels within what is left of PHP's memory_limit. */
    private function decodeFits(int $pixels): bool
    {
        $limit = $this->bytes((string) ini_get('memory_limit'));
        return $limit < 0 || $pixels * self::GD_BYTES_PER_PIXEL < $limit - memory_get_usage();
    }

    /** php.ini shorthand ("128M", "-1") as bytes; -1 for no limit. */
    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** The client's file name without any path, control characters or excess length; "image" when nothing is left. */
    private function displayName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', str_replace('\\', '/', $name));
        $name = trim(mb_substr(basename($name), 0, 120, 'UTF-8'));
        return $name === '' || ! mb_check_encoding($name, 'UTF-8') ? 'image' : $name;
    }
}
