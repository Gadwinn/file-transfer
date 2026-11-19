<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests;

use G\FileTransfer\Driver\SftpDriver;
use G\FileTransfer\FileTransfer;
use G\FileTransfer\UniversalTransferAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileTransfer::class)]
final class FileTransferTest extends TestCase
{
    /** @var array<string, mixed> */
    private const VALID_CONFIG = [
        'host' => 'sftp.example.com',
        'username' => 'deploy',
        'password' => 's3cr3t',
    ];

    #[Test]
    public function itCreatesADriverViaTheDefaultFactory(): void
    {
        $driver = FileTransfer::create(SftpDriver::class, self::VALID_CONFIG);

        self::assertInstanceOf(UniversalTransferAdapterInterface::class, $driver);
        self::assertInstanceOf(SftpDriver::class, $driver);
    }

    #[Test]
    public function itReturnsANewDriverOnEveryCall(): void
    {
        self::assertNotSame(
            FileTransfer::create(SftpDriver::class, self::VALID_CONFIG),
            FileTransfer::create(SftpDriver::class, self::VALID_CONFIG),
        );
    }
}
