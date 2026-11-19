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
 * The facade holds no state. To swap the factory (DI container, test double),
 * depend on {@see TransferFactoryInterface} and inject {@see TransferFactory} or
 * your own implementation instead of calling the facade.
 */
final class FileTransfer
{
    /**
     * Prevent direct instantiation.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
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
        return (new TransferFactory())->create($driverClass, $config, $collaborator);
    }
}
