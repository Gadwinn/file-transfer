<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests\Driver;

use G\FileTransfer\Driver\SftpDriver;
use G\FileTransfer\Exception\ConfigurationException;
use G\FileTransfer\Exception\ConfigurationExceptionInterface;
use G\FileTransfer\Exception\ConnectionException;
use G\FileTransfer\Exception\ConnectionExceptionInterface;
use G\FileTransfer\Exception\FileNotFoundException;
use G\FileTransfer\Exception\TransferExceptionInterface;
use G\FileTransfer\UniversalTransferAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see SftpDriver}.
 *
 * These tests exercise the pure, deterministic surface of the driver:
 * constructor validation and the "not connected" guards. Every public
 * operation funnels through {@see SftpDriver::requireSftp()} before touching
 * any ext-ssh2 function, so these cases run without a live server and without
 * the extension being loaded. Real transfer paths (connect, upload, download,
 * listing) require an integration suite against an SFTP server.
 */
#[CoversClass(SftpDriver::class)]
final class SftpDriverTest extends TestCase
{
    /** @var array<string, mixed> */
    private const VALID_CONFIG = [
        'host' => 'sftp.example.com',
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
            new SftpDriver(self::VALID_CONFIG),
        );
    }

    #[Test]
    public function itIsNotConnectedBeforeConnect(): void
    {
        self::assertFalse((new SftpDriver(self::VALID_CONFIG))->isConnected());
    }

    #[Test]
    public function disconnectIsAHarmlessNoOpWhenNotConnected(): void
    {
        $driver = new SftpDriver(self::VALID_CONFIG);

        $driver->disconnect();

        self::assertFalse($driver->isConnected());
    }

    #[Test]
    public function itAcceptsKeyPairAuthentication(): void
    {
        $privateKey = $this->createTempFile();
        $publicKey = $this->createTempFile();

        $driver = new SftpDriver([
            'host' => 'sftp.example.com',
            'username' => 'deploy',
            'privateKey' => $privateKey,
            'publicKey' => $publicKey,
            'passphrase' => 'opensesame',
        ]);

        self::assertFalse($driver->isConnected());
    }

    #[Test]
    public function itAcceptsACustomIntegerPort(): void
    {
        $driver = new SftpDriver(['host' => 'h', 'port' => 2222, 'username' => 'u', 'password' => 'p']);

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

        new SftpDriver($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'missing host' => [['username' => 'u', 'password' => 'p'], 'host'];
        yield 'empty host' => [['host' => '', 'username' => 'u', 'password' => 'p'], 'host'];
        yield 'non-string host' => [['host' => 123, 'username' => 'u', 'password' => 'p'], 'host'];
        yield 'non-int port' => [['host' => 'h', 'port' => '22', 'username' => 'u', 'password' => 'p'], 'port'];
        yield 'missing username' => [['host' => 'h', 'password' => 'p'], 'username'];
        yield 'empty username' => [['host' => 'h', 'username' => '', 'password' => 'p'], 'username'];
        yield 'no auth method' => [['host' => 'h', 'username' => 'u'], 'authentication'];
        yield 'private key without public key' => [
            ['host' => 'h', 'username' => 'u', 'privateKey' => '/tmp/id_rsa'],
            'authentication',
        ];
        yield 'non-string password' => [['host' => 'h', 'username' => 'u', 'password' => 123], 'password'];
        yield 'missing private key file' => [
            ['host' => 'h', 'username' => 'u', 'privateKey' => '/no/such/priv', 'publicKey' => '/no/such/pub'],
            'Private key file',
        ];
    }

    #[Test]
    public function existsRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new SftpDriver(self::VALID_CONFIG))->exists('/remote/file.txt');
    }

    #[Test]
    public function deleteRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new SftpDriver(self::VALID_CONFIG))->delete('/remote/file.txt');
    }

    #[Test]
    public function createDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new SftpDriver(self::VALID_CONFIG))->createDirectory('/remote/dir');
    }

    #[Test]
    public function deleteDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new SftpDriver(self::VALID_CONFIG))->deleteDirectory('/remote/dir');
    }

    #[Test]
    public function listDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        // The generator body only runs once iteration begins.
        iterator_to_array((new SftpDriver(self::VALID_CONFIG))->listDirectory('/'));
    }

    #[Test]
    public function uploadStreamRequiresAnActiveConnection(): void
    {
        $stream = $this->memoryStream();

        $this->expectException(ConnectionException::class);

        (new SftpDriver(self::VALID_CONFIG))->uploadStream($stream, '/remote/file.txt');
    }

    #[Test]
    public function downloadToStreamRequiresAnActiveConnection(): void
    {
        $stream = $this->memoryStream();

        $this->expectException(ConnectionException::class);

        (new SftpDriver(self::VALID_CONFIG))->downloadToStream('/remote/file.txt', $stream);
    }

    #[Test]
    public function uploadFileRequiresAnActiveConnectionOnceTheLocalSourceExists(): void
    {
        $local = $this->createTempFile();

        $this->expectException(ConnectionException::class);

        (new SftpDriver(self::VALID_CONFIG))->uploadFile($local, '/remote/file.txt');
    }

    #[Test]
    public function uploadFileRejectsAMissingLocalSourceBeforeConnecting(): void
    {
        $this->expectException(FileNotFoundException::class);

        (new SftpDriver(self::VALID_CONFIG))->uploadFile('/no/such/local/file', '/remote/file.txt');
    }

    #[Test]
    public function downloadToFileRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new SftpDriver(self::VALID_CONFIG))->downloadToFile('/remote/file.txt', $this->createTempFile());
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
