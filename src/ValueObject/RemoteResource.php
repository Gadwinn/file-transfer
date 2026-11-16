<?php

declare(strict_types=1);

namespace G\FileTransfer\ValueObject;

use DateTimeImmutable;

/**
 * Immutable description of a single remote filesystem item.
 *
 * The supplied {@see DateTimeImmutable} is expected to already be anchored to
 * UTC by the producing driver, honouring the strict temporal contract of
 * {@see RemoteResourceInterface::getLastModified()}.
 */
final class RemoteResource implements RemoteResourceInterface
{
    public function __construct(
        private readonly string $path,
        private readonly string $name,
        private readonly int $size,
        private readonly DateTimeImmutable $lastModified,
        private readonly bool $isDirectory,
        private readonly bool $isFile,
        private readonly PermissionsInterface $permissions,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getLastModified(): DateTimeImmutable
    {
        return $this->lastModified;
    }

    public function isDirectory(): bool
    {
        return $this->isDirectory;
    }

    public function isFile(): bool
    {
        return $this->isFile;
    }

    public function getPermissions(): PermissionsInterface
    {
        return $this->permissions;
    }
}
