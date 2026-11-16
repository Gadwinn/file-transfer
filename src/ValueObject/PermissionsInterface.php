<?php

declare(strict_types=1);

namespace G\FileTransfer\ValueObject;

/**
 * Immutable, protocol-agnostic representation of POSIX-style access modes.
 *
 * This contract eliminates ad hoc permission-string parsing across
 * transports by normalizing every driver onto a single deterministic shape.
 * Transports that do not expose granular permission bits MUST degrade to a
 * predictable fallback (see {@see PermissionsInterface::toString()}) rather
 * than guessing or emitting transport-specific notation.
 */
interface PermissionsInterface
{
    /**
     * Canonical 4-character octal notation of the access mode.
     *
     * Implementations MUST always return exactly four octal digits
     * (e.g. "0644", "0755"). When the underlying transport cannot supply
     * granular permissions, the value MUST default to the predictable
     * fallback "0000".
     */
    public function toString(): string;

    /**
     * Whether the owner triad carries the read bit.
     */
    public function isReadableByOwner(): bool;

    /**
     * Whether the owner triad carries the write bit.
     */
    public function isWritableByOwner(): bool;

    /**
     * Whether the owner triad carries the execute bit.
     */
    public function isExecutableByOwner(): bool;

    /**
     * Whether the group triad carries the read bit.
     */
    public function isReadableByGroup(): bool;

    /**
     * Whether the group triad carries the write bit.
     */
    public function isWritableByGroup(): bool;

    /**
     * Whether the group triad carries the execute bit.
     */
    public function isExecutableByGroup(): bool;

    /**
     * Whether the public (other) triad carries the read bit.
     */
    public function isReadableByPublic(): bool;

    /**
     * Whether the public (other) triad carries the write bit.
     */
    public function isWritableByPublic(): bool;

    /**
     * Whether the public (other) triad carries the execute bit.
     */
    public function isExecutableByPublic(): bool;
}
