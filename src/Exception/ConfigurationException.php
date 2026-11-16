<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

use InvalidArgumentException;

/**
 * Concrete configuration-time failure.
 *
 * Thrown when a driver is requested with an unresolvable class, or wired
 * with missing or malformed parameters (credentials, host, invalid keys).
 * Extends the native {@see InvalidArgumentException} so it also fits generic
 * SPL catch blocks while remaining isolatable through the library's unified
 * {@see ConfigurationExceptionInterface} boundary.
 */
final class ConfigurationException extends InvalidArgumentException implements ConfigurationExceptionInterface
{
}
