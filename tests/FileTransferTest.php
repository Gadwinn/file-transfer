<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests;

use G\FileTransfer\Driver\SftpDriver;
use G\FileTransfer\FileTransfer;
use G\FileTransfer\Factory\TransferFactoryInterface;
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

    protected function tearDown(): void
    {
        // The facade holds static state; restore the default factory between tests.
        FileTransfer::setFactory(null);
    }

    #[Test]
    public function itCreatesADriverViaTheDefaultFactory(): void
    {
        $driver = FileTransfer::create(SftpDriver::class, self::VALID_CONFIG);

        self::assertInstanceOf(UniversalTransferAdapterInterface::class, $driver);
        self::assertInstanceOf(SftpDriver::class, $driver);
    }

    #[Test]
    public function itDelegatesToAnInjectedFactory(): void
    {
        $adapter = $this->createStub(UniversalTransferAdapterInterface::class);

        $factory = $this->createMock(TransferFactoryInterface::class);
        $factory->expects($this->once())
            ->method('create')
            ->with(SftpDriver::class, ['k' => 'v'], null)
            ->willReturn($adapter);

        FileTransfer::setFactory($factory);

        self::assertSame($adapter, FileTransfer::create(SftpDriver::class, ['k' => 'v']));
    }

    #[Test]
    public function itForwardsACollaboratorToTheFactory(): void
    {
        $adapter = $this->createStub(UniversalTransferAdapterInterface::class);
        $collaborator = new \stdClass();

        $factory = $this->createMock(TransferFactoryInterface::class);
        $factory->expects($this->once())
            ->method('create')
            ->with(SftpDriver::class, ['k' => 'v'], $collaborator)
            ->willReturn($adapter);

        FileTransfer::setFactory($factory);

        self::assertSame($adapter, FileTransfer::create(SftpDriver::class, ['k' => 'v'], $collaborator));
    }

    #[Test]
    public function settingNullRestoresTheDefaultFactory(): void
    {
        $rogue = $this->createMock(TransferFactoryInterface::class);
        $rogue->expects($this->never())->method('create');

        FileTransfer::setFactory($rogue);
        FileTransfer::setFactory(null);

        $driver = FileTransfer::create(SftpDriver::class, self::VALID_CONFIG);

        self::assertInstanceOf(UniversalTransferAdapterInterface::class, $driver);
    }
}
