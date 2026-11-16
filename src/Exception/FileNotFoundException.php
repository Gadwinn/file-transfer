<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

use RuntimeException;

/**
 * Concrete failure raised when a referenced remote path does not exist.
 */
final class FileNotFoundException extends RuntimeException implements FileNotFoundExceptionInterface
{
}
