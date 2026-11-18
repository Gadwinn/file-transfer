<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests\Driver;

use DateTimeImmutable;
use DateTimeZone;
use G\FileTransfer\Driver\FtpDriver;
use G\FileTransfer\Exception\ConfigurationException;
use G\FileTransfer\Exception\ConfigurationExceptionInterface;
use G\FileTransfer\Exception\ConnectionException;
use G\FileTransfer\Exception\ConnectionExceptionInterface;
use G\FileTransfer\Exception\FileNotFoundException;
use G\FileTransfer\Exception\TransferExceptionInterface;
use G\FileTransfer\UniversalTransferAdapterInterface;
use G\FileTransfer\ValueObject\RemoteResourceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionMethod;

/**
 * Unit coverage for {@see FtpDriver}.
 *
 * The deterministic surface — constructor validation, the "not connected"
 * guards, the extension guard, and the pure raw-listing parser — is exercised
 * without a live FTP server and without `ext-ftp` being loaded. Live transfer
 * paths (connect, put, get, rawlist over the wire) belong to an integration
 * suite running against a real FTP/FTPS endpoint.
 */
#[CoversClass(FtpDriver::class)]
final class FtpDriverTest extends TestCase
{
    /** @var array<string, mixed> */
    private const VALID_CONFIG = [
        'host' => 'ftp.example.com',
        'username' => 'deploy',
        'password' => 's3cr3t',
    ];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    #[Test]
    public function itImplementsTheUnifiedContract(): void
    {
        self::assertInstanceOf(
            UniversalTransferAdapterInterface::class,
            new FtpDriver(self::VALID_CONFIG, $this->clockAt('2026-06-02T00:00:00+00:00')),
        );
    }

    #[Test]
    public function itIsNotConnectedBeforeConnect(): void
    {
        self::assertFalse($this->driver()->isConnected());
    }

    #[Test]
    public function disconnectIsAHarmlessNoOpWhenNotConnected(): void
    {
        $driver = $this->driver();

        $driver->disconnect();

        self::assertFalse($driver->isConnected());
    }

    #[Test]
    public function itAcceptsCustomPortTimeoutAndSslFlags(): void
    {
        $driver = new FtpDriver(
            ['host' => 'h', 'port' => 990, 'username' => 'u', 'password' => 'p', 'timeout' => 30, 'ssl' => true],
            $this->clockAt('2026-06-02T00:00:00+00:00'),
        );

        self::assertFalse($driver->isConnected());
    }

    #[Test]
    public function itAcceptsAnEmptyPasswordForAnonymousAccess(): void
    {
        $driver = new FtpDriver(
            ['host' => 'h', 'username' => 'anonymous', 'password' => ''],
            $this->clockAt('2026-06-02T00:00:00+00:00'),
        );

        self::assertFalse($driver->isConnected());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('invalidConfigProvider')]
    public function itRejectsInvalidConfiguration(array $config, string $messageFragment): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($messageFragment);

        new FtpDriver($config, $this->clockAt('2026-06-02T00:00:00+00:00'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'missing host' => [['username' => 'u', 'password' => 'p'], 'host'];
        yield 'empty host' => [['host' => '', 'username' => 'u', 'password' => 'p'], 'host'];
        yield 'non-string host' => [['host' => 123, 'username' => 'u', 'password' => 'p'], 'host'];
        yield 'non-int port' => [['host' => 'h', 'port' => '21', 'username' => 'u', 'password' => 'p'], 'port'];
        yield 'missing username' => [['host' => 'h', 'password' => 'p'], 'username'];
        yield 'empty username' => [['host' => 'h', 'username' => '', 'password' => 'p'], 'username'];
        yield 'missing password' => [['host' => 'h', 'username' => 'u'], 'password'];
        yield 'non-string password' => [['host' => 'h', 'username' => 'u', 'password' => 123], 'password'];
        yield 'non-int timeout' => [
            ['host' => 'h', 'username' => 'u', 'password' => 'p', 'timeout' => '90'],
            'timeout',
        ];
        yield 'non-positive timeout' => [
            ['host' => 'h', 'username' => 'u', 'password' => 'p', 'timeout' => 0],
            'timeout',
        ];
        yield 'non-bool ssl' => [['host' => 'h', 'username' => 'u', 'password' => 'p', 'ssl' => 'yes'], 'ssl'];
    }

    #[Test]
    public function existsRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        $this->driver()->exists('/remote/file.txt');
    }

    #[Test]
    public function deleteRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        $this->driver()->delete('/remote/file.txt');
    }

    #[Test]
    public function createDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        $this->driver()->createDirectory('/remote/dir');
    }

    #[Test]
    public function deleteDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        $this->driver()->deleteDirectory('/remote/dir');
    }

    #[Test]
    public function listDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        // The generator body only runs once iteration begins.
        iterator_to_array($this->driver()->listDirectory('/'));
    }

    #[Test]
    public function uploadStreamRequiresAnActiveConnection(): void
    {
        $stream = $this->memoryStream();

        $this->expectException(ConnectionException::class);

        $this->driver()->uploadStream($stream, '/remote/file.txt');
    }

    #[Test]
    public function downloadToStreamRequiresAnActiveConnection(): void
    {
        $stream = $this->memoryStream();

        $this->expectException(ConnectionException::class);

        $this->driver()->downloadToStream('/remote/file.txt', $stream);
    }

    #[Test]
    public function uploadFileRequiresAnActiveConnectionOnceTheLocalSourceExists(): void
    {
        $local = $this->createTempFile();

        $this->expectException(ConnectionException::class);

        $this->driver()->uploadFile($local, '/remote/file.txt');
    }

    #[Test]
    public function uploadFileRejectsAMissingLocalSourceBeforeConnecting(): void
    {
        $this->expectException(FileNotFoundException::class);

        $this->driver()->uploadFile('/no/such/local/file', '/remote/file.txt');
    }

    #[Test]
    public function downloadToFileRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        $this->driver()->downloadToFile('/remote/file.txt', $this->createTempFile());
    }

    #[Test]
    public function connectFailsWithConfigurationErrorWhenExtensionIsMissing(): void
    {
        if (extension_loaded('ftp')) {
            self::markTestSkipped('ext-ftp is loaded; the missing-extension guard cannot be exercised here.');
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('ext-ftp');

        $this->driver()->connect();
    }

    #[Test]
    public function connectFailsWithConnectionErrorOnAnUnreachableHost(): void
    {
        if (!extension_loaded('ftp')) {
            self::markTestSkipped('ext-ftp is required to exercise the live connection failure path.');
        }

        $driver = new FtpDriver(
            ['host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'p', 'timeout' => 1],
            $this->clockAt('2026-06-02T00:00:00+00:00'),
        );

        $this->expectException(ConnectionException::class);

        $driver->connect();
    }

    #[Test]
    public function itParsesAFileListingLineIntoAUtcResource(): void
    {
        $resource = $this->parseLine(
            '-rw-r--r--   1 owner group     1024 Jan 15  2021 readme.txt',
        );

        self::assertInstanceOf(RemoteResourceInterface::class, $resource);
        self::assertSame('/remote/readme.txt', $resource->getPath());
        self::assertSame('readme.txt', $resource->getName());
        self::assertSame(1024, $resource->getSize());
        self::assertTrue($resource->isFile());
        self::assertFalse($resource->isDirectory());
        self::assertSame('0644', $resource->getPermissions()->toString());
        self::assertSame('UTC', $resource->getLastModified()->getTimezone()->getName());
        self::assertSame('2021-01-15 00:00:00', $resource->getLastModified()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function itParsesADirectoryListingLineWithTheClockAnchoredYear(): void
    {
        // The time-only form omits the year; the clock supplies it.
        $resource = $this->parseLine(
            'drwxr-xr-x   2 owner group     4096 Jun  2 14:30 uploads',
        );

        self::assertInstanceOf(RemoteResourceInterface::class, $resource);
        self::assertSame('uploads', $resource->getName());
        self::assertTrue($resource->isDirectory());
        self::assertFalse($resource->isFile());
        self::assertSame('0755', $resource->getPermissions()->toString());
        self::assertSame('UTC', $resource->getLastModified()->getTimezone()->getName());
        self::assertSame('2026-06-02 14:30:00', $resource->getLastModified()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function itSkipsTheTotalsHeaderLine(): void
    {
        self::assertNull($this->parseLine('total 8'));
    }

    #[Test]
    public function itStripsTheTargetFromASymbolicLinkName(): void
    {
        $resource = $this->parseLine('lrwxrwxrwx   1 owner group       11 Jan 15  2021 current -> releases/42');

        self::assertInstanceOf(RemoteResourceInterface::class, $resource);
        self::assertSame('current', $resource->getName());
        self::assertSame('/remote/current', $resource->getPath());
        self::assertFalse($resource->isDirectory());
        self::assertFalse($resource->isFile());
    }

    #[Test]
    public function itParsesAnMlsdEntry(): void
    {
        $resource = $this->parseFacts([
            'name' => 'report.pdf',
            'type' => 'file',
            'size' => '2048',
            'modify' => '20240315103045.123',
            'UNIX.mode' => '0640',
        ]);

        self::assertInstanceOf(RemoteResourceInterface::class, $resource);
        self::assertSame('/remote/report.pdf', $resource->getPath());
        self::assertSame(2048, $resource->getSize());
        self::assertTrue($resource->isFile());
        self::assertSame('0640', $resource->getPermissions()->toString());
        self::assertSame('2024-03-15 10:30:45', $resource->getLastModified()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $resource->getLastModified()->getTimezone()->getName());
    }

    #[Test]
    public function itParsesAnMlsdDirectoryAndSkipsTheSelfEntries(): void
    {
        $directory = $this->parseFacts(['name' => 'logs', 'Type' => 'DIR', 'modify' => '20240101000000']);

        self::assertInstanceOf(RemoteResourceInterface::class, $directory);
        self::assertTrue($directory->isDirectory());
        self::assertSame('0000', $directory->getPermissions()->toString());
        self::assertNull($this->parseFacts(['name' => '.', 'type' => 'cdir']));
        self::assertNull($this->parseFacts(['name' => '..', 'type' => 'pdir']));
    }

    #[Test]
    public function itsExceptionsShareTheUnifiedBoundary(): void
    {
        $configError = new ConfigurationException('x');
        $connectionError = new ConnectionException('x');

        self::assertInstanceOf(ConfigurationExceptionInterface::class, $configError);
        self::assertInstanceOf(ConnectionExceptionInterface::class, $connectionError);
        self::assertInstanceOf(TransferExceptionInterface::class, $configError);
        self::assertInstanceOf(TransferExceptionInterface::class, $connectionError);
    }

    private function driver(): FtpDriver
    {
        // Clock anchored late in the year so time-only listing dates never fall
        // "in the future" and trigger the previous-year correction.
        return new FtpDriver(self::VALID_CONFIG, $this->clockAt('2026-12-31T23:59:59+00:00'));
    }

    private function parseLine(string $line): ?RemoteResourceInterface
    {
        $method = new ReflectionMethod(FtpDriver::class, 'describe');

        /** @var RemoteResourceInterface|null $resource */
        $resource = $method->invoke($this->driver(), $line, '/remote');

        return $resource;
    }

    /**
     * @param array<string, string> $facts
     */
    private function parseFacts(array $facts): ?RemoteResourceInterface
    {
        $method = new ReflectionMethod(FtpDriver::class, 'describeFacts');

        /** @var RemoteResourceInterface|null $resource */
        $resource = $method->invoke($this->driver(), $facts, '/remote');

        return $resource;
    }

    private function clockAt(string $iso): ClockInterface
    {
        $instant = new DateTimeImmutable($iso, new DateTimeZone('UTC'));

        return new class ($instant) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }

    private function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ft_');
        self::assertIsString($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return resource
     */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'rb+');
        self::assertIsResource($stream);

        return $stream;
    }
}
