<?php

/**
 * Image bytes and upload arrays for the image tests: real images drawn with GD, a PNG whose
 * header alone claims a size (no pixel data needed to test the decompression guard), and
 * PHP's $_FILES entries written to a temp dir the caller removes.
 */
final class ImageFixtures
{
    public function __construct(public readonly string $dir)
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
    }

    public static function tempDir(string $prefix): string
    {
        return sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
    }

    public static function png(int $w = 40, int $h = 30): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    /** Random pixels: a PNG that barely compresses, about 3 bytes per pixel. */
    public static function noise(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($img, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    public static function gif(): string
    {
        $img = imagecreatetruecolor(20, 20);
        ob_start();
        imagegif($img);
        return (string) ob_get_clean();
    }

    /** A PNG signature and IHDR claiming $w x $h, then IEND: getimagesize() reads the size, GD never decodes it. */
    public static function pngHeader(int $w, int $h): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IEND', '');
    }

    public static function svg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
    }

    public static function html(): string
    {
        return "<!DOCTYPE html><html><body><script>alert(document.cookie)</script></body></html>\n";
    }

    /** One $_FILES-style entry for $bytes, as PHP hands an uploaded file to the request. */
    public function upload(string $bytes, string $name = 'photo.png', int $error = UPLOAD_ERR_OK): array
    {
        $path = $this->dir.'/up-'.bin2hex(random_bytes(6));
        file_put_contents($path, $bytes);
        return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $path, 'error' => $error, 'size' => strlen($bytes)];
    }

    /** Uploads in PHP's `images[]` shape: one array per field, each a list. */
    public static function field(array ...$uploads): array
    {
        $field = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
            $field[$key] = array_column($uploads, $key);
        }
        return $field;
    }

    public static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
