<?php

declare(strict_types=1);

namespace G\FileTransfer\Factory;

use ArgumentCountError;
use G\FileTransfer\Exception\ConfigurationException;
use G\FileTransfer\UniversalTransferAdapterInterface;
use TypeError;

use function class_exists;
use function is_subclass_of;
use function sprintf;

/**
 * Default factory that instantiates driver classes dynamically.
 *
 * The driver class must accept its connection settings as a single
 * `array<string, mixed> $config` constructor argument. Drivers needing
 * richer wiring (injected clock, logger, DI-managed dependencies) should
 * provide a custom {@see TransferFactoryInterface} via
 * {@see \G\FileTransfer\FileTransfer::setFactory()}.
 *
 * @template T of UniversalTransferAdapterInterface
 * @implements TransferFactoryInterface<T>
 */
final class TransferFactory implements TransferFactoryInterface
{
    /**
     * @param class-string<T>      $driverClass
     * @param array<string, mixed> $config
     * @param mixed                $collaborator Optional collaborator forwarded as
     *                                           the driver constructor's second argument.
     *
     * @return T
     */
    public function create(
        string $driverClass,
        array $config = [],
        mixed $collaborator = null,
    ): UniversalTransferAdapterInterface {
        if (!class_exists($driverClass)) {
            throw new ConfigurationException(sprintf(
                'Transfer driver class "%s" does not exist or could not be autoloaded.',
                $driverClass,
            ));
        }

        if (!is_subclass_of($driverClass, UniversalTransferAdapterInterface::class)) {
            throw new ConfigurationException(sprintf(
                'Transfer driver class "%s" does not implement %s.',
                $driverClass,
                UniversalTransferAdapterInterface::class,
            ));
        }

        try {
            /** @var T $driver */
            $driver = $collaborator === null
                ? new $driverClass($config)
                : new $driverClass($config, $collaborator);
        } catch (ArgumentCountError | TypeError $e) {
            // Missing or wrong collaborator (clock, S3 client...).
            throw new ConfigurationException(sprintf(
                'Transfer driver "%s" could not be built: %s',
                $driverClass,
                $e->getMessage(),
            ), 0, $e);
        }

        return $driver;
    }
}
