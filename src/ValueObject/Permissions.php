<?php

declare(strict_types=1);

namespace G\FileTransfer\ValueObject;

use function sprintf;

/**
 * Immutable POSIX access-mode value object.
 *
 * Construct from the raw permission bits (the low 12 bits of a `st_mode`
 * value). Transports unable to report granular permissions construct with
 * {@see Permissions::none()}, which renders as the deterministic "0000".
 */
final class Permissions implements PermissionsInterface
{
    private const PERMISSION_MASK = 0o7777;

    private const OWNER_READ = 0o400;
    private const OWNER_WRITE = 0o200;
    private const OWNER_EXEC = 0o100;

    private const GROUP_READ = 0o040;
    private const GROUP_WRITE = 0o020;
    private const GROUP_EXEC = 0o010;

    private const PUBLIC_READ = 0o004;
    private const PUBLIC_WRITE = 0o002;
    private const PUBLIC_EXEC = 0o001;

    private readonly int $mode;

    /**
     * @param int $mode Raw access mode; only the low permission bits are kept.
     */
    public function __construct(int $mode)
    {
        $this->mode = $mode & self::PERMISSION_MASK;
    }

    /**
     * Predictable fallback for transports without granular permissions.
     */
    public static function none(): self
    {
        return new self(0);
    }

    public function toString(): string
    {
        return sprintf('%04o', $this->mode);
    }

    public function isReadableByOwner(): bool
    {
        return ($this->mode & self::OWNER_READ) !== 0;
    }

    public function isWritableByOwner(): bool
    {
        return ($this->mode & self::OWNER_WRITE) !== 0;
    }

    public function isExecutableByOwner(): bool
    {
        return ($this->mode & self::OWNER_EXEC) !== 0;
    }

    public function isReadableByGroup(): bool
    {
        return ($this->mode & self::GROUP_READ) !== 0;
    }

    public function isWritableByGroup(): bool
    {
        return ($this->mode & self::GROUP_WRITE) !== 0;
    }

    public function isExecutableByGroup(): bool
    {
        return ($this->mode & self::GROUP_EXEC) !== 0;
    }

    public function isReadableByPublic(): bool
    {
        return ($this->mode & self::PUBLIC_READ) !== 0;
    }

    public function isWritableByPublic(): bool
    {
        return ($this->mode & self::PUBLIC_WRITE) !== 0;
    }

    public function isExecutableByPublic(): bool
    {
        return ($this->mode & self::PUBLIC_EXEC) !== 0;
    }
}
