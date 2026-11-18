<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests\Driver;

use G\FileTransfer\Driver\LocalDriver;
use G\FileTransfer\Exception\ConfigurationException;
use G\FileTransfer\Exception\ConnectionException;
use G\FileTransfer\Exception\FileNotFoundException;
use G\FileTransfer\Exception\IOException;
use G\FileTransfer\FileTransfer;
use G\FileTransfer\UniversalTransferAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalDriver::class)]
final class LocalDriverTest extends TestCase
{
    private string $root;
    private string $outside;
    private LocalDriver $driver;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g_file_transfer_' . bin2hex(random_bytes(6));
        $this->root = $base . DIRECTORY_SEPARATOR . 'root';
        $this->outside = $base . DIRECTORY_SEPARATOR . 'outside';
        mkdir($this->root, 0o777, true);
        mkdir($this->outside, 0o777, true);

        $this->driver = new LocalDriver(['root' => $this->root]);
        $this->driver->connect();
    }

    protected function tearDown(): void
    {
        $this->remove(dirname($this->root));
    }

    // -------------------------------------------------------------------------
    // Configuration and connection
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigProvider')]
    public function testRootIsRequired(array $config): void
    {
        $this->expectException(ConfigurationException::class);

        new LocalDriver($config);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidConfigProvider(): array
    {
        return ['missing' => [[]], 'empty' => [['root' => '']], 'not a string' => [['root' => 42]]];
    }

    public function testConnectionLifecycle(): void
    {
        $driver = new LocalDriver(['root' => $this->root]);

        self::assertFalse($driver->isConnected());
        $driver->connect();
        $driver->connect();
        self::assertTrue($driver->isConnected());
        $driver->disconnect();
        $driver->disconnect();
        self::assertFalse($driver->isConnected());
    }

    public function testConnectFailsWhenTheRootDoesNotExist(): void
    {
        $driver = new LocalDriver(['root' => $this->root . DIRECTORY_SEPARATOR . 'missing']);

        $this->expectException(ConnectionException::class);

        $driver->connect();
    }

    public function testOperationsRequireAConnection(): void
    {
        $driver = new LocalDriver(['root' => $this->root]);

        $this->expectException(ConnectionException::class);

        $driver->exists('/file.txt');
    }

    public function testFacadeCreatesTheDriver(): void
    {
        $driver = FileTransfer::create(LocalDriver::class, ['root' => $this->root]);

        self::assertInstanceOf(UniversalTransferAdapterInterface::class, $driver);
        self::assertInstanceOf(LocalDriver::class, $driver);
    }

    // -------------------------------------------------------------------------
    // Transfers
    // -------------------------------------------------------------------------

    public function testUploadFileThenDownloadToFile(): void
    {
        $source = $this->outside . DIRECTORY_SEPARATOR . 'source.txt';
        $target = $this->outside . DIRECTORY_SEPARATOR . 'target.txt';
        file_put_contents($source, 'payload');

        $this->driver->uploadFile($source, '/report.txt');
        $this->driver->downloadToFile('report.txt', $target);

        self::assertSame('payload', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'report.txt'));
        self::assertSame('payload', file_get_contents($target));
    }

    public function testUploadStreamAndDownloadToStreamLeaveTheStreamsOpen(): void
    {
        $source = $this->stream('stream payload');
        $target = $this->stream('');

        $this->driver->uploadStream($source, '/data.bin');
        $this->driver->downloadToStream('/data.bin', $target);

        self::assertIsResource($source);
        self::assertIsResource($target);
        rewind($target);
        self::assertSame('stream payload', stream_get_contents($target));
    }

    public function testUploadOverwritesAnExistingFile(): void
    {
        $this->driver->uploadStream($this->stream('first, longer content'), '/data.txt');
        $this->driver->uploadStream($this->stream('second'), '/data.txt');

        self::assertSame('second', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'data.txt'));
    }

    public function testUploadFileFailsWhenTheLocalFileIsMissing(): void
    {
        $this->expectException(FileNotFoundException::class);

        $this->driver->uploadFile($this->outside . DIRECTORY_SEPARATOR . 'missing.txt', '/report.txt');
    }

    public function testUploadFailsWhenTheParentDirectoryIsMissing(): void
    {
        $this->expectException(IOException::class);

        $this->driver->uploadStream($this->stream('x'), '/missing/dir/file.txt');
    }

    public function testDownloadFailsWhenTheRemoteFileIsMissing(): void
    {
        $this->expectException(FileNotFoundException::class);

        $this->driver->downloadToStream('/missing.txt', $this->stream(''));
    }

    public function testDownloadToFileDoesNotCreateTheLocalFileWhenTheRemoteIsMissing(): void
    {
        $target = $this->outside . DIRECTORY_SEPARATOR . 'target.txt';

        try {
            $this->driver->downloadToFile('/missing.txt', $target);
            self::fail('Expected a FileNotFoundException.');
        } catch (FileNotFoundException) {
            self::assertFileDoesNotExist($target);
        }
    }

    public function testADirectoryIsNotADownloadableFile(): void
    {
        $this->driver->createDirectory('/docs');

        $this->expectException(FileNotFoundException::class);

        $this->driver->downloadToStream('/docs', $this->stream(''));
    }

    // -------------------------------------------------------------------------
    // Filesystem operations
    // -------------------------------------------------------------------------

    public function testExistsAndDelete(): void
    {
        self::assertFalse($this->driver->exists('/file.txt'));

        $this->driver->uploadStream($this->stream('x'), '/file.txt');
        self::assertTrue($this->driver->exists('/file.txt'));
        self::assertTrue($this->driver->exists('/'));

        $this->driver->delete('/file.txt');
        self::assertFalse($this->driver->exists('/file.txt'));
    }

    public function testDeleteFailsWhenTheFileIsMissing(): void
    {
        $this->expectException(FileNotFoundException::class);

        $this->driver->delete('/missing.txt');
    }

    public function testCreateDirectoryIsIdempotent(): void
    {
        $this->driver->createDirectory('/docs');
        $this->driver->createDirectory('/docs');

        self::assertDirectoryExists($this->root . DIRECTORY_SEPARATOR . 'docs');
    }

    public function testCreateDirectoryFailsWhenTheParentIsMissing(): void
    {
        $this->expectException(IOException::class);

        $this->driver->createDirectory('/a/b/c');
    }

    public function testDeleteDirectoryRefusesANonEmptyDirectoryUnlessRecursive(): void
    {
        $this->driver->createDirectory('/docs');
        $this->driver->createDirectory('/docs/sub');
        $this->driver->uploadStream($this->stream('x'), '/docs/a.txt');
        $this->driver->uploadStream($this->stream('y'), '/docs/sub/b.txt');

        try {
            $this->driver->deleteDirectory('/docs');
            self::fail('Expected an IOException for a non-empty directory.');
        } catch (IOException) {
            self::assertTrue($this->driver->exists('/docs/sub/b.txt'));
        }

        $this->driver->deleteDirectory('/docs', true);

        self::assertFalse($this->driver->exists('/docs'));
    }

    public function testRecursiveDeleteRemovesSymbolicLinksWithoutFollowingThem(): void
    {
        file_put_contents($this->outside . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');
        $this->driver->createDirectory('/docs');
        $this->symlinkOrSkip($this->outside, $this->root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'link');

        $entries = iterator_to_array($this->driver->listDirectory('/docs'), false);
        self::assertCount(1, $entries);
        self::assertFalse($entries[0]->isDirectory(), 'a link is not reported as a directory');

        $this->driver->deleteDirectory('/docs', true);

        self::assertFalse($this->driver->exists('/docs'));
        self::assertFileExists($this->outside . DIRECTORY_SEPARATOR . 'keep.txt');
    }

    public function testDeleteDirectoryRefusesASymbolicLink(): void
    {
        $this->symlinkOrSkip($this->outside, $this->root . DIRECTORY_SEPARATOR . 'link');

        $this->expectException(IOException::class);

        $this->driver->deleteDirectory('/link', true);
    }

    public function testDeleteDirectoryFailsWhenMissing(): void
    {
        $this->expectException(FileNotFoundException::class);

        $this->driver->deleteDirectory('/missing');
    }

    public function testTheRootItselfCannotBeRemoved(): void
    {
        $this->expectException(IOException::class);

        $this->driver->deleteDirectory('/', true);
    }

    public function testListDirectoryDescribesItsChildren(): void
    {
        $this->driver->createDirectory('/docs');
        $this->driver->createDirectory('/docs/sub');
        $this->driver->uploadStream($this->stream('12345'), '/docs/a.txt');

        $entries = [];
        foreach ($this->driver->listDirectory('docs/') as $resource) {
            $entries[$resource->getName()] = $resource;
        }
        ksort($entries);

        self::assertSame(['a.txt', 'sub'], array_keys($entries));
        self::assertSame('/docs/a.txt', $entries['a.txt']->getPath());
        self::assertTrue($entries['a.txt']->isFile());
        self::assertFalse($entries['a.txt']->isDirectory());
        self::assertSame(5, $entries['a.txt']->getSize());
        self::assertSame('UTC', $entries['a.txt']->getLastModified()->getTimezone()->getName());
        self::assertEqualsWithDelta(time(), $entries['a.txt']->getLastModified()->getTimestamp(), 60);
        self::assertTrue($entries['a.txt']->getPermissions()->isReadableByOwner());
        self::assertSame('/docs/sub', $entries['sub']->getPath());
        self::assertTrue($entries['sub']->isDirectory());
    }

    public function testListDirectoryOfTheRoot(): void
    {
        $this->driver->uploadStream($this->stream('x'), '/a.txt');

        $paths = [];
        foreach ($this->driver->listDirectory() as $resource) {
            $paths[] = $resource->getPath();
        }

        self::assertSame(['/a.txt'], $paths);
    }

    public function testListDirectoryFailsWhenMissing(): void
    {
        $this->expectException(FileNotFoundException::class);

        iterator_to_array($this->driver->listDirectory('/missing'));
    }

    // -------------------------------------------------------------------------
    // Confinement to the root directory
    // -------------------------------------------------------------------------

    #[DataProvider('escapingPathProvider')]
    public function testPathsCannotLeaveTheRoot(string $path): void
    {
        file_put_contents($this->outside . DIRECTORY_SEPARATOR . 'secret.txt', 'secret');

        try {
            $this->driver->downloadToStream($path, $this->stream(''));
            self::fail('Expected an IOException for ' . $path);
        } catch (IOException $exception) {
            self::assertStringContainsString('outside of the root directory', $exception->getMessage());
        }

        try {
            $this->driver->uploadStream($this->stream('overwritten'), $path);
            self::fail('Expected an IOException for ' . $path);
        } catch (IOException) {
            self::assertSame('secret', file_get_contents($this->outside . DIRECTORY_SEPARATOR . 'secret.txt'));
        }
    }

    /** @return array<string, array{string}> */
    public static function escapingPathProvider(): array
    {
        return [
            'parent' => ['../outside/secret.txt'],
            'absolute parent' => ['/../outside/secret.txt'],
            'nested parent' => ['/docs/../../outside/secret.txt'],
            'backslashes' => ['..\\outside\\secret.txt'],
            'null byte' => ["/secret.txt\0.png"],
        ];
    }

    public function testEquivalentSpellingsReachTheSameFile(): void
    {
        $this->driver->createDirectory('/docs');
        $this->driver->uploadStream($this->stream('x'), '/docs/a.txt');

        foreach (['docs/a.txt', '//docs///a.txt', '/./docs/./a.txt', 'docs\\a.txt'] as $path) {
            self::assertTrue($this->driver->exists($path), $path);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function symlinkOrSkip(string $target, string $link): void
    {
        if (!@symlink($target, $link)) {
            self::markTestSkipped('Symbolic links cannot be created here (Windows without the privilege).');
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path)) {
            // Remove the link itself, never what it points to.
            @unlink($path) || @rmdir($path);

            return;
        }

        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }
}
