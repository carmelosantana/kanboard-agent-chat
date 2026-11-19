<?php
use PHPUnit\Framework\TestCase;

// vendor-chatparts/ is a hash-checked copy (scripts/sync-chatparts.sh): every file matches CHECKSUMS, nothing extra.
class ChatPartsVendorTest extends TestCase
{
    public function testVendoredFilesMatchChecksums(): void
    {
        $root = __DIR__.'/../vendor-chatparts';
        $this->assertFileExists("$root/CHECKSUMS");

        $listed = ['CHECKSUMS'];
        foreach (file("$root/CHECKSUMS", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}  \S.*$/', $line);
            [$hash, $path] = [substr($line, 0, 64), substr($line, 66)];
            $this->assertFileExists("$root/$path");
            $this->assertSame($hash, hash_file('sha256', "$root/$path"), $path);
            $listed[] = $path;
        }

        foreach (['autoload.php', 'LICENSE', 'THIRD_PARTY.md'] as $required) {
            $this->assertContains($required, $listed);
        }

        $actual = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $actual[] = substr($file->getPathname(), strlen($root) + 1);
        }
        sort($listed);
        sort($actual);
        $this->assertSame($listed, $actual, 'vendor-chatparts holds exactly the files in CHECKSUMS');
    }
}
