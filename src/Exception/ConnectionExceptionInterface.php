<?php

declare(strict_types=1);

namespace G\FileTransfer\Exception;

/**
 * Raised when establishing, authenticating, or maintaining the network
 * link to the remote endpoint fails.
 *
 * This covers unreachable hosts, refused connections, handshake or TLS
 * negotiation failures, rejected credentials, and unexpected session
 * termination.
 */
interface ConnectionExceptionInterface extends TransferExceptionInterface
{
}
