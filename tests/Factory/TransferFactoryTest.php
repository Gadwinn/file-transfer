<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests\Factory;

use DateTimeImmutable;
use G\FileTransfer\Driver\FtpDriver;
use G\FileTransfer\Driver\SftpDriver;
use G\FileTransfer\Exception\ConfigurationException;
use G\FileTransfer\Factory\TransferFactory;
use G\FileTransfer\Factory\TransferFactoryInterface;
use G\FileTransfer\UniversalTransferAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(TransferFactory::class)]
final class TransferFactoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private const VALID_CONFIG = [
        'host' => 'sftp.example.com',
        'username' => 'deploy',
        'password' => 's3cr3t',
    ];

    #[Test]
    public function itImplementsTheContract(): void
    {
        self::assertInstanceOf(TransferFactoryInterface::class, new TransferFactory());
    }

    #[Test]
    public function itInstantiatesADriverFromAValidClass(): void
    {
        $driver = (new TransferFactory())->create(SftpDriver::class, self::VALID_CONFIG);

        self::assertInstanceOf(UniversalTransferAdapterInterface::class, $driver);
        self::assertInstanceOf(SftpDriver::class, $driver);
        self::assertFalse($driver->isConnected());
    }

    #[Test]
    public function itForwardsConfigurationToTheDriverConstructor(): void
    {
        // A malformed config must surface the driver's own ConfigurationException.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('host');

        (new TransferFactory())->create(SftpDriver::class, ['username' => 'u', 'password' => 'p']);
    }

    #[Test]
    public function itForwardsACollaboratorAsTheSecondConstructorArgument(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@0');
            }
        };

        $driver = (new TransferFactory())->create(
            FtpDriver::class,
            ['host' => 'ftp.example.com', 'username' => 'deploy', 'password' => 's3cr3t'],
            $clock,
        );

        self::assertInstanceOf(FtpDriver::class, $driver);
        self::assertFalse($driver->isConnected());
    }

    #[Test]
    public function itRejectsAnUnresolvableClass(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('does not exist');

        /** @phpstan-ignore-next-line — intentionally passing an unknown class */
        (new TransferFactory())->create('G\\FileTransfer\\Driver\\NonExistentDriver', self::VALID_CONFIG);
    }
}
