<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

/**
 * Raised when a read or write operation fails after the link is healthy.
 *
 * This covers permission denials, exhausted quotas, truncated or
 * interrupted streams, unreadable local sources, unwritable local
 * targets, and any other I/O fault that is not a missing resource and
 * not a connection-level failure.
 */
interface IOExceptionInterface extends TransferExceptionInterface
{
}
