<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

use RuntimeException;

/**
 * Concrete runtime failure of the network link or authentication step.
 */
final class ConnectionException extends RuntimeException implements ConnectionExceptionInterface
{
}
