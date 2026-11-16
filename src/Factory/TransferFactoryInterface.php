<?php

declare(strict_types=1);

namespace G\FileTransfer\Factory;

use G\FileTransfer\Exception\ConfigurationExceptionInterface;
use G\FileTransfer\UniversalTransferAdapterInterface;

/**
 * Contract for instantiating transfer driver instances.
 *
 * @template T of UniversalTransferAdapterInterface
 */
interface TransferFactoryInterface
{
    /**
     * Create and return a new, ready-to-use driver for the given class.
     *
     * The configuration map is forwarded to the driver constructor; its
     * required shape is defined by each concrete driver (e.g. host, port,
     * username, password, private key, bucket, region).
     *
     * Drivers that depend on a collaborator (e.g. `FtpDriver` needs a
     * `Psr\Clock\ClockInterface`, `S3Driver` an `Aws\S3\S3ClientInterface`)
     * receive it as the optional third argument, forwarded as the second
     * constructor parameter. Leave it null for drivers built from config alone.
     *
     * @param class-string<T>      $driverClass  Fully qualified driver class name.
     * @param array<string, mixed> $config       Driver-specific connection settings.
     * @param mixed                $collaborator Optional driver collaborator passed
     *                                           as the constructor's second argument.
     *
     * @return T A ready-to-use driver instance.
     *
     * @throws ConfigurationExceptionInterface When the class cannot be
     *                                         resolved or the configuration
     *                                         is missing or malformed.
     */
    public function create(
        string $driverClass,
        array $config = [],
        mixed $collaborator = null,
    ): UniversalTransferAdapterInterface;
}
