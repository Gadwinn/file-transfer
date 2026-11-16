<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

/**
 * Raised when a driver is constructed or invoked with missing or malformed
 * parameters.
 *
 * This is a configuration-time fault, distinct from runtime link failures:
 * absent credentials, an empty or invalid host, an unreadable private key,
 * an unsupported option, or any other ill-formed bootstrap value. It signals
 * a defect in how the driver was wired, not in the remote endpoint.
 */
interface ConfigurationExceptionInterface extends TransferExceptionInterface
{
}
