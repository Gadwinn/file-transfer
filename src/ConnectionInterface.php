<?php

declare(strict_types=1);

namespace G\FileTransfer;

use G\FileTransfer\Exception\ConfigurationExceptionInterface;
use G\FileTransfer\Exception\ConnectionExceptionInterface;

/**
 * Governs the lifecycle of the underlying network link.
 *
 * Implementations are responsible for translating transport-specific
 * connection faults into the library's exception hierarchy, ensuring the
 * link state is never exposed through leaking native errors.
 */
interface ConnectionInterface
{
    /**
     * Establishes and authenticates the session with the remote endpoint.
     *
     * Implementations SHOULD be idempotent: invoking this method on an
     * already-open link MUST NOT corrupt the existing session.
     *
     * @throws ConfigurationExceptionInterface When the driver was wired with
     *                                         missing or malformed parameters.
     * @throws ConnectionExceptionInterface    When the link cannot be opened
     *                                         or authentication is rejected.
     */
    public function connect(): void;

    /**
     * Securely closes active sockets or channels and releases all transport
     * resources.
     *
     * Calling this method on an already-closed link MUST be a harmless
     * no-op rather than an error condition.
     */
    public function disconnect(): void;

    /**
     * Reports whether a live, usable session is currently established.
     */
    public function isConnected(): bool;
}
