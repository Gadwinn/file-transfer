<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

/**
 * Raised when a referenced remote file or directory does not exist.
 *
 * Typically thrown by read-oriented operations (download, metadata
 * retrieval, directory traversal) when the supplied remote path cannot
 * be resolved on the target filesystem.
 */
interface FileNotFoundExceptionInterface extends TransferExceptionInterface
{
}
