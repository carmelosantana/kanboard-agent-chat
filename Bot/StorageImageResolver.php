<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Contract\ImageResolverInterface;
use CarmeloSantana\PHPAgents\Image\ImageInputException;
use CarmeloSantana\PHPAgents\Image\ResolvedImage;
use Kanboard\Plugin\AgentChat\Model\FileModel;
use Pimple\Container;

/**
 * Hands the bot a chat image from Kanboard's objectStorage. A reference is `agentchat-file:{id}`
 * and nothing else (no path, no URL); the file must belong to the run's room and be one the
 * run's asker may see (FileModel::canRead, the rule the file endpoint uses). The stored bytes
 * are sniffed again (FileModel::sniff, not php-agents' ImagePolicy, which needs the fileinfo
 * extension Kanboard's Docker image lacks), and that MIME type is the one returned.
 * Anything refused is an ImageInputException, php-agents' expected failure.
 */
final class StorageImageResolver implements ImageResolverInterface
{
    public const PREFIX = 'agentchat-file:';

    public function __construct(private readonly Container $container, private readonly int $askerId, private readonly int $roomId)
    {
    }

    public static function reference(int $fileId): string
    {
        return self::PREFIX.$fileId;
    }

    public function resolve(string $reference): ResolvedImage
    {
        if (preg_match('/^'.preg_quote(self::PREFIX, '/').'([1-9][0-9]{0,17})$/D', $reference, $m) !== 1) {
            throw new ImageInputException('Only agentchat-file references are accepted.');
        }
        $files = new FileModel($this->container);
        $file = $files->get((int) $m[1]);
        if ($file === null || (int) $file['room_id'] !== $this->roomId || ! $files->canRead($this->askerId, $file)) {
            throw new ImageInputException('That image is not available to this run.');
        }
        $bytes = $files->read($file, false);
        if ($bytes === null) {
            throw new ImageInputException('That image could not be read.');
        }
        $image = strlen($bytes) > FileModel::MAX_BYTES ? null : FileModel::sniff($bytes);
        if ($image === null) {
            throw new ImageInputException('That image is not an allowed image.');
        }
        return new ResolvedImage($image['mime'], $bytes);
    }
}
