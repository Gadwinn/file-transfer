<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

use Throwable;

/**
 * Base marker contract for every exception emitted by the library.
 *
 * Concrete drivers MUST ensure that any throwable escaping their public
 * surface implements this interface, so that consumers can isolate the
 * library behind a single, mechanism-agnostic catch clause without ever
 * depending on driver-specific throwables (e.g. ext-ftp warnings,
 * phpseclib exceptions, AWS SDK errors).
 */
interface TransferExceptionInterface extends Throwable
{
}
