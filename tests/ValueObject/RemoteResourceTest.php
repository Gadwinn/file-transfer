<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use G\FileTransfer\ValueObject\Permissions;
use G\FileTransfer\ValueObject\RemoteResource;
use G\FileTransfer\ValueObject\RemoteResourceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteResource::class)]
final class RemoteResourceTest extends TestCase
{
    #[Test]
    public function itExposesEveryFileAttribute(): void
    {
        $lastModified = new DateTimeImmutable('2026-06-02 12:34:56', new DateTimeZone('UTC'));
        $permissions = new Permissions(0o644);

        $resource = new RemoteResource(
            '/remote/path/report.pdf',
            'report.pdf',
            2048,
            $lastModified,
            false,
            true,
            $permissions,
        );

        self::assertInstanceOf(RemoteResourceInterface::class, $resource);
        self::assertSame('/remote/path/report.pdf', $resource->getPath());
        self::assertSame('report.pdf', $resource->getName());
        self::assertSame(2048, $resource->getSize());
        self::assertSame($lastModified, $resource->getLastModified());
        self::assertSame('UTC', $resource->getLastModified()->getTimezone()->getName());
        self::assertFalse($resource->isDirectory());
        self::assertTrue($resource->isFile());
        self::assertSame($permissions, $resource->getPermissions());
    }

    #[Test]
    public function itRepresentsADirectory(): void
    {
        $resource = new RemoteResource(
            '/remote/uploads',
            'uploads',
            0,
            new DateTimeImmutable('@0'),
            true,
            false,
            Permissions::none(),
        );

        self::assertTrue($resource->isDirectory());
        self::assertFalse($resource->isFile());
        self::assertSame(0, $resource->getSize());
        self::assertSame('0000', $resource->getPermissions()->toString());
    }
}
