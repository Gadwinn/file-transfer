<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

use RuntimeException;

/**
 * Concrete read/write failure occurring once the link is healthy.
 */
final class IOException extends RuntimeException implements IOExceptionInterface
{
}
