<?php

declare(strict_types=1);

namespace G\FileTransfer;

use G\FileTransfer\Factory\TransferFactory;
use G\FileTransfer\Factory\TransferFactoryInterface;

/**
 * Static facade providing the main entry point of the library.
 *
 * Drop in any protocol driver class — the facade returns a fully wired
 * {@see UniversalTransferAdapterInterface}, so consumer code stays identical
 * across FTP, SFTP, S3, WebDAV, and any future transport.
 *
 * Usage:
 * ```php
 * use G\FileTransfer\FileTransfer;
 * use G\FileTransfer\Driver\SftpDriver;
 *
 * $transfer = FileTransfer::create(SftpDriver::class, [
 *     'host'     => 'ftp.example.com',
 *     'port'     => 22,
 *     'username' => 'deploy',
 *     'password' => 's3cr3t',
 * ]);
 *
 * $transfer->connect();
 * $transfer->uploadFile('/local/report.pdf', '/remote/report.pdf');
 * $transfer->disconnect();
 * ```
 *
 * A custom factory can be injected (e.g. in tests or DI containers):
 * ```php
 * FileTransfer::setFactory(new MyCustomFactory());
 * ```
 */
final class FileTransfer
{
    /** @var TransferFactoryInterface<UniversalTransferAdapterInterface>|null */
    private static ?TransferFactoryInterface $factory = null;

    /**
     * Prevent direct instantiation.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Override the default {@see TransferFactory} with a custom implementation.
     *
     * Useful for dependency injection or test doubles.
     * Pass null to restore the default factory.
     *
     * @param TransferFactoryInterface<UniversalTransferAdapterInterface>|null $factory
     *        Custom factory, or null to reset.
     */
    public static function setFactory(?TransferFactoryInterface $factory): void
    {
        self::$factory = $factory;
    }

    /**
     * Create and return a new driver instance for the given protocol class.
     *
     * For IDE type-narrowing on the concrete driver type, use
     * {@see TransferFactory::create()} directly:
     * ```php
     * $driver = (new TransferFactory())->create(SftpDriver::class, $config); // typed as SftpDriver
     * ```
     *
     * Drivers that require a collaborator (e.g. `FtpDriver` a
     * `Psr\Clock\ClockInterface`, `S3Driver` an `Aws\S3\S3ClientInterface`)
     * receive it through the optional third argument:
     * ```php
     * $transfer = FileTransfer::create(S3Driver::class, ['bucket' => 'acme'], $s3Client);
     * ```
     *
     * @param class-string<UniversalTransferAdapterInterface> $driverClass
     *        Fully qualified protocol driver class name.
     * @param array<string, mixed>                            $config
     *        Driver-specific connection settings.
     * @param mixed                                           $collaborator
     *        Optional driver collaborator (clock, S3 client, ...).
     */
    public static function create(
        string $driverClass,
        array $config = [],
        mixed $collaborator = null,
    ): UniversalTransferAdapterInterface {
        return (self::$factory ?? new TransferFactory())->create($driverClass, $config, $collaborator);
    }
}
