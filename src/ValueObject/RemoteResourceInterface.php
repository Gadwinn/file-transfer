<?php

declare(strict_types=1);

namespace G\FileTransfer\ValueObject;

use DateTimeImmutable;

/**
 * Immutable description of a single item residing on a remote filesystem.
 *
 * Drivers MUST expose directory listings and metadata lookups exclusively
 * through implementations of this contract, never as raw arrays or
 * delimited strings, guaranteeing a stable, strongly typed shape across
 * every transport (FTP, SFTP, S3, WebDAV, ...).
 */
interface RemoteResourceInterface
{
    /**
     * Absolute, normalized path of the resource on the remote endpoint.
     */
    public function getPath(): string;

    /**
     * Terminal segment of the path (the file or directory name only).
     */
    public function getName(): string;

    /**
     * Size of the resource in bytes; implementations SHOULD report 0 for
     * directories when the transport does not provide a meaningful value.
     */
    public function getSize(): int;

    /**
     * Timestamp of the last known modification.
     *
     * Strict requirement: the returned instance MUST always be anchored to
     * the UTC timezone, guaranteeing deterministic temporal comparisons
     * regardless of the host's local timezone or the transport's native
     * representation.
     */
    public function getLastModified(): DateTimeImmutable;

    /**
     * Whether the resource is a directory (container) node.
     */
    public function isDirectory(): bool;

    /**
     * Whether the resource is a regular file (leaf) node.
     */
    public function isFile(): bool;

    /**
     * Normalized access mode of the resource as a strict value object,
     * never as a transport-specific string.
     */
    public function getPermissions(): PermissionsInterface;
}
