<?php

declare(strict_types=1);

namespace G\FileTransfer;

/**
 * The supreme, unified boundary contract for the library.
 *
 * Aggregates connection lifecycle, payload transfer, and remote filesystem
 * management into a single cohesive surface. Client code SHOULD type-hint
 * against this interface to remain fully decoupled from any concrete driver
 * (FTP, SFTP, S3, WebDAV, ...), swapping mechanisms without touching call
 * sites.
 *
 * Time-anchor mandate: concrete adapters MUST accept a
 * {@see \Psr\Clock\ClockInterface} through their constructor and source every
 * internally generated timestamp from it, so that temporal behavior remains
 * deterministic and fully testable. This obligation is intentionally placed
 * on the implementation constructor rather than on this pure behavioral
 * contract, keeping the boundary free of construction concerns.
 */
interface UniversalTransferAdapterInterface extends
    ConnectionInterface,
    TransferInterface,
    FilesystemOperationsInterface
{
}
