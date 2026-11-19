<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AgentChat\Model\FileModel;

/**
 * Serves a chat image (show) or its PNG thumbnail (thumb). The route has no project_id: the
 * file's message, room and project decide (FileModel::canRead). 404 for a file, thumbnail or
 * blob that does not exist; 403 for one the viewer may not see.
 *
 * The response carries the stored (sniffed) type, never a guess from the name, and is locked
 * down: nosniff, a sandboxing CSP, inline with an ASCII-only file name, privately cached.
 */
class FileController extends BaseController
{
    public const CACHE_SECONDS = 3600;

    /** Sent by PHP's session cache limiter at session start; they contradict Cache-Control here. */
    public const DROPPED_HEADERS = ['Expires', 'Pragma'];

    public function show(): void
    {
        $this->serve(false);
    }

    public function thumb(): void
    {
        $this->serve(true);
    }

    private function serve(bool $thumb): void
    {
        $files = new FileModel($this->container);
        $file = $files->get($this->request->getIntegerParam('id'));
        if ($file === null) {
            $this->response->status(404);
            return;
        }
        if (! $files->canRead((int) $this->userSession->getId(), $file)) {
            throw new AccessForbiddenException();
        }
        $bytes = $files->read($file, $thumb);
        if ($bytes === null) {
            $this->response->status(404);
            return;
        }

        foreach (headers_sent() ? [] : self::DROPPED_HEADERS as $header) {
            header_remove($header);
        }
        $this->response
            ->withStatusCode(200)
            ->withContentType($thumb ? 'image/png' : (string) $file['mime'])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Content-Disposition', 'inline; filename="'.$this->asciiName((string) $file['name'], $thumb).'"')
            ->withHeader('Cache-Control', 'private, max-age='.self::CACHE_SECONDS)
            ->withBody($bytes)
            ->send();
    }

    /** The name with anything but ASCII letters, digits, dot, dash and underscore replaced; never empty. */
    private function asciiName(string $name, bool $thumb): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '._');
        $safe = substr($safe === '' ? 'image' : $safe, 0, 100);
        return $thumb ? pathinfo($safe, PATHINFO_FILENAME).'-thumb.png' : $safe;
    }
}
