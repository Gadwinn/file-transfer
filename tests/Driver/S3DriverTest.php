<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests\Driver;

use Aws\Api\DateTimeResult;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;
use G\FileTransfer\Driver\S3Driver;
use G\FileTransfer\Exception\ConfigurationException;
use G\FileTransfer\Exception\ConfigurationExceptionInterface;
use G\FileTransfer\Exception\ConnectionException;
use G\FileTransfer\Exception\ConnectionExceptionInterface;
use G\FileTransfer\Exception\FileNotFoundException;
use G\FileTransfer\Exception\IOException;
use G\FileTransfer\Exception\TransferExceptionInterface;
use G\FileTransfer\UniversalTransferAdapterInterface;
use G\FileTransfer\ValueObject\RemoteResourceInterface;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

/**
 * Unit coverage for {@see S3Driver}.
 *
 * The driver is exercised against a mocked {@see S3ClientInterface}: `getCommand`
 * yields real {@see Command} objects and `execute` returns real {@see Result}
 * objects, so request shaping, pagination, listing translation and the
 * exception boundary are all verified deterministically without any network or
 * AWS credentials.
 */
#[CoversClass(S3Driver::class)]
final class S3DriverTest extends TestCase
{
    private const BUCKET = 'my-bucket';

    /** @var array<string, mixed> */
    private const VALID_CONFIG = ['bucket' => self::BUCKET];

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
            new S3Driver(self::VALID_CONFIG, $this->client()),
        );
    }

    #[Test]
    public function itIsNotConnectedBeforeConnect(): void
    {
        self::assertFalse((new S3Driver(self::VALID_CONFIG, $this->client()))->isConnected());
    }

    #[Test]
    public function connectProbesTheBucketAndReportsConnected(): void
    {
        $client = $this->client();
        $client->method('execute')->willReturnCallback($this->router());

        $driver = new S3Driver(self::VALID_CONFIG, $client);
        $driver->connect();

        self::assertTrue($driver->isConnected());

        $driver->disconnect();
        self::assertFalse($driver->isConnected());
    }

    #[Test]
    public function connectTranslatesAwsFailuresIntoConnectionException(): void
    {
        $client = $this->client();
        $client->method('execute')->willThrowException(
            new AwsException('access denied', new Command('HeadBucket')),
        );

        $this->expectException(ConnectionException::class);

        (new S3Driver(self::VALID_CONFIG, $client))->connect();
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

        new S3Driver($config, $this->client());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'missing bucket' => [[], 'bucket'];
        yield 'empty bucket' => [['bucket' => ''], 'bucket'];
        yield 'non-string bucket' => [['bucket' => 123], 'bucket'];
        yield 'non-string prefix' => [['bucket' => self::BUCKET, 'prefix' => 123], 'prefix'];
    }

    #[Test]
    public function existsRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new S3Driver(self::VALID_CONFIG, $this->client()))->exists('/file.txt');
    }

    #[Test]
    public function deleteRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new S3Driver(self::VALID_CONFIG, $this->client()))->delete('/file.txt');
    }

    #[Test]
    public function createDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new S3Driver(self::VALID_CONFIG, $this->client()))->createDirectory('/dir');
    }

    #[Test]
    public function deleteDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new S3Driver(self::VALID_CONFIG, $this->client()))->deleteDirectory('/dir');
    }

    #[Test]
    public function listDirectoryRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        // The generator body only runs once iteration begins.
        iterator_to_array((new S3Driver(self::VALID_CONFIG, $this->client()))->listDirectory('/'));
    }

    #[Test]
    public function uploadStreamRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new S3Driver(self::VALID_CONFIG, $this->client()))->uploadStream($this->memoryStream(), '/file.txt');
    }

    #[Test]
    public function downloadToStreamRequiresAnActiveConnection(): void
    {
        $this->expectException(ConnectionException::class);

        (new S3Driver(self::VALID_CONFIG, $this->client()))->downloadToStream('/file.txt', $this->memoryStream());
    }

    #[Test]
    public function uploadFileRequiresAnActiveConnectionOnceTheLocalSourceExists(): void
    {
        $local = $this->createTempFile();

        $this->expectException(ConnectionException::class);

        (new S3Driver(self::VALID_CONFIG, $this->client()))->uploadFile($local, '/file.txt');
    }

    #[Test]
    public function uploadFileRejectsAMissingLocalSourceBeforeConnecting(): void
    {
        $this->expectException(FileNotFoundException::class);

        (new S3Driver(self::VALID_CONFIG, $this->client()))->uploadFile('/no/such/local/file', '/file.txt');
    }

    #[Test]
    public function uploadFileSendsAPutObjectWithTheSourceFile(): void
    {
        $local = $this->createTempFile();
        $captured = null;

        $client = $this->client();
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command) use (&$captured): ?Result {
                if ($command->getName() === 'PutObject') {
                    $captured = $command->toArray();

                    return new Result([]);
                }

                return null;
            },
        ));

        $driver = $this->connected($client);
        $driver->uploadFile($local, '/reports/q1.pdf');

        self::assertIsArray($captured);
        self::assertSame(self::BUCKET, $captured['Bucket']);
        self::assertSame('reports/q1.pdf', $captured['Key']);
        self::assertSame($local, $captured['SourceFile']);
    }

    #[Test]
    public function uploadStreamSendsAPutObjectCarryingTheStreamBody(): void
    {
        $stream = $this->memoryStream();
        fwrite($stream, 'skipped|payload');
        fseek($stream, strlen('skipped|'));
        $captured = null;
        $body = null;

        $client = $this->client();
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command) use (&$captured, &$body): ?Result {
                if ($command->getName() === 'PutObject') {
                    $captured = $command->toArray();
                    self::assertInstanceOf(StreamInterface::class, $captured['Body']);
                    $body = (string) $captured['Body'];

                    return new Result([]);
                }

                return null;
            },
        ));

        $this->connected($client)->uploadStream($stream, '/data/payload.bin');

        self::assertIsArray($captured);
        self::assertSame('data/payload.bin', $captured['Key']);
        self::assertSame('payload', $body, 'read from the current position');
        self::assertIsResource($stream, 'the caller keeps an open stream');
    }

    #[Test]
    public function uploadStreamRejectsANonResource(): void
    {
        $this->expectException(IOException::class);

        $this->connected($this->client())->uploadStream('not a stream', '/data/payload.bin');
    }

    #[Test]
    public function deleteDirectoryRefusesAFile(): void
    {
        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(true);
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command): ?Result {
                if ($command->getName() === 'ListObjectsV2') {
                    return new Result([]);
                }

                return null;
            },
        ));

        $this->expectException(FileNotFoundException::class);

        $this->connected($client)->deleteDirectory('/file.txt');
    }

    #[Test]
    public function existsIsTrueWhenTheObjectIsPresent(): void
    {
        $client = $this->client();
        $client->method('execute')->willReturnCallback($this->router());
        $client->method('doesObjectExist')->willReturn(true);

        self::assertTrue($this->connected($client)->exists('/file.txt'));
    }

    #[Test]
    public function existsFallsBackToAPrefixProbeForDirectories(): void
    {
        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(false);
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command): ?Result {
                if ($command->getName() === 'ListObjectsV2') {
                    return new Result(['Contents' => [['Key' => 'incoming/a.txt']]]);
                }

                return null;
            },
        ));

        self::assertTrue($this->connected($client)->exists('/incoming'));
    }

    #[Test]
    public function existsIsFalseWhenNeitherObjectNorPrefixIsPresent(): void
    {
        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(false);
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command): ?Result {
                if ($command->getName() === 'ListObjectsV2') {
                    return new Result([]);
                }

                return null;
            },
        ));

        self::assertFalse($this->connected($client)->exists('/nope'));
    }

    #[Test]
    public function downloadToStreamCopiesTheObjectBody(): void
    {
        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(true);
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command): ?Result {
                if ($command->getName() === 'GetObject') {
                    return new Result(['Body' => Utils::streamFor('hello world')]);
                }

                return null;
            },
        ));

        $target = $this->memoryStream();
        $this->connected($client)->downloadToStream('/greeting.txt', $target);

        rewind($target);
        self::assertSame('hello world', stream_get_contents($target));
    }

    #[Test]
    public function downloadToFileRejectsAMissingRemoteObject(): void
    {
        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(false);
        $client->method('execute')->willReturnCallback($this->router());

        $this->expectException(FileNotFoundException::class);

        $this->connected($client)->downloadToFile('/missing.txt', $this->createTempFile());
    }

    #[Test]
    public function deleteRejectsAMissingRemoteObject(): void
    {
        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(false);
        $client->method('execute')->willReturnCallback($this->router());

        $this->expectException(FileNotFoundException::class);

        $this->connected($client)->delete('/missing.txt');
    }

    #[Test]
    public function createDirectoryWritesAZeroBytePlaceholder(): void
    {
        $captured = null;

        $client = $this->client();
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command) use (&$captured): ?Result {
                if ($command->getName() === 'PutObject') {
                    $captured = $command->toArray();

                    return new Result([]);
                }

                return null;
            },
        ));

        $this->connected($client)->createDirectory('/releases/2026');

        self::assertIsArray($captured);
        self::assertSame('releases/2026/', $captured['Key']);
        self::assertSame('', $captured['Body']);
    }

    #[Test]
    public function deleteDirectoryRefusesANonEmptyDirectoryWithoutRecursion(): void
    {
        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(false);
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command): ?Result {
                if ($command->getName() === 'ListObjectsV2') {
                    // Both the existence probe and the listing see one child.
                    return new Result(['Contents' => [['Key' => 'logs/app.log', 'Size' => 10]]]);
                }

                return null;
            },
        ));

        $this->expectException(IOException::class);

        $this->connected($client)->deleteDirectory('/logs');
    }

    #[Test]
    public function deleteDirectoryRecursivelyRemovesEveryMatchingObject(): void
    {
        $deleted = [];

        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(false);
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command) use (&$deleted): ?Result {
                if ($command->getName() === 'ListObjectsV2') {
                    return new Result([
                        'Contents' => [
                            ['Key' => 'logs/'],
                            ['Key' => 'logs/app.log'],
                            ['Key' => 'logs/old/app.log'],
                        ],
                    ]);
                }

                if ($command->getName() === 'DeleteObject') {
                    $deleted[] = $command['Key'];

                    return new Result([]);
                }

                return null;
            },
        ));

        $this->connected($client)->deleteDirectory('/logs', recursive: true);

        self::assertSame(['logs/', 'logs/app.log', 'logs/old/app.log'], $deleted);
    }

    #[Test]
    public function listDirectoryYieldsUtcTypedResourcesAcrossPages(): void
    {
        $client = $this->client();
        $client->method('doesObjectExist')->willReturn(false);
        $client->method('execute')->willReturnCallback($this->router(
            static function (CommandInterface $command): ?Result {
                if ($command->getName() !== 'ListObjectsV2') {
                    return null;
                }

                // Existence probe: no delimiter -> report a hit so the directory "exists".
                if (!isset($command['Delimiter'])) {
                    return new Result(['Contents' => [['Key' => 'uploads/a.txt']]]);
                }

                // First listing page.
                if (($command['ContinuationToken'] ?? null) === null) {
                    return new Result([
                        'CommonPrefixes' => [['Prefix' => 'uploads/sub/']],
                        'Contents' => [
                            // The directory placeholder itself must be skipped.
                            ['Key' => 'uploads/', 'Size' => 0, 'LastModified' => new DateTimeImmutable('@0')],
                            [
                                'Key' => 'uploads/a.txt',
                                'Size' => 1024,
                                'LastModified' => new DateTimeImmutable('2021-01-15T08:30:00+00:00'),
                            ],
                        ],
                        'IsTruncated' => true,
                        'NextContinuationToken' => 'PAGE2',
                    ]);
                }

                // Second (final) page.
                return new Result([
                    'Contents' => [
                        [
                            'Key' => 'uploads/b.bin',
                            'Size' => 2048,
                            'LastModified' => new DateTimeResult('2022-03-04T05:06:07+00:00'),
                        ],
                    ],
                    'IsTruncated' => false,
                ]);
            },
        ));

        /** @var list<RemoteResourceInterface> $items */
        $items = iterator_to_array($this->connected($client)->listDirectory('/uploads'), false);

        self::assertCount(3, $items);

        [$dir, $first, $second] = $items;

        self::assertSame('sub', $dir->getName());
        self::assertSame('/uploads/sub', $dir->getPath());
        self::assertTrue($dir->isDirectory());
        self::assertFalse($dir->isFile());
        self::assertSame('0000', $dir->getPermissions()->toString());

        self::assertSame('a.txt', $first->getName());
        self::assertSame('/uploads/a.txt', $first->getPath());
        self::assertSame(1024, $first->getSize());
        self::assertTrue($first->isFile());
        self::assertSame('0000', $first->getPermissions()->toString());
        self::assertSame('UTC', $first->getLastModified()->getTimezone()->getName());
        self::assertSame('2021-01-15 08:30:00', $first->getLastModified()->format('Y-m-d H:i:s'));

        self::assertSame('b.bin', $second->getName());
        self::assertSame(2048, $second->getSize());
        self::assertSame('2022-03-04 05:06:07', $second->getLastModified()->format('Y-m-d H:i:s'));
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

    /**
     * Build a stubbed client whose `getCommand` produces real Command objects.
     *
     * @return Stub&S3ClientInterface
     */
    private function client(): Stub
    {
        $client = $this->createStub(S3ClientInterface::class);
        $client->method('getCommand')->willReturnCallback(
            /** @param array<string, mixed> $args */
            static fn(string $name, array $args = []): Command => new Command($name, $args),
        );

        return $client;
    }

    /**
     * Compose an `execute` handler: the optional callback handles operation
     * commands (returning null to defer), while HeadBucket and any unhandled
     * command resolve to an empty {@see Result}.
     *
     * @param (callable(CommandInterface): ?Result)|null $handler
     *
     * @return callable(CommandInterface): Result
     */
    private function router(?callable $handler = null): callable
    {
        return static function (CommandInterface $command) use ($handler): Result {
            if ($handler !== null) {
                $result = $handler($command);
                if ($result instanceof Result) {
                    return $result;
                }
            }

            return new Result([]);
        };
    }

    /**
     * @param Stub&S3ClientInterface $client
     */
    private function connected(Stub $client): S3Driver
    {
        $driver = new S3Driver(self::VALID_CONFIG, $client);
        $driver->connect();

        return $driver;
    }

    private function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 's3_');
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
