<?php

declare(strict_types=1);

namespace G\FileTransfer\Tests\ValueObject;

use G\FileTransfer\ValueObject\Permissions;
use G\FileTransfer\ValueObject\PermissionsInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Permissions::class)]
final class PermissionsTest extends TestCase
{
    #[Test]
    public function itImplementsTheContract(): void
    {
        self::assertInstanceOf(PermissionsInterface::class, new Permissions(0o644));
    }

    #[Test]
    public function noneIsTheDeterministicFallback(): void
    {
        $permissions = Permissions::none();

        self::assertSame('0000', $permissions->toString());
        self::assertFalse($permissions->isReadableByOwner());
        self::assertFalse($permissions->isWritableByOwner());
        self::assertFalse($permissions->isExecutableByOwner());
        self::assertFalse($permissions->isReadableByGroup());
        self::assertFalse($permissions->isWritableByGroup());
        self::assertFalse($permissions->isExecutableByGroup());
        self::assertFalse($permissions->isReadableByPublic());
        self::assertFalse($permissions->isWritableByPublic());
        self::assertFalse($permissions->isExecutableByPublic());
    }

    #[Test]
    #[DataProvider('octalProvider')]
    public function itRendersAFourDigitOctalString(int $mode, string $expected): void
    {
        self::assertSame($expected, (new Permissions($mode))->toString());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function octalProvider(): iterable
    {
        yield 'none' => [0, '0000'];
        yield 'common file' => [0o644, '0644'];
        yield 'common dir' => [0o755, '0755'];
        yield 'full' => [0o777, '0777'];
        yield 'sticky set' => [0o1755, '1755'];
    }

    #[Test]
    public function itMasksNonPermissionBits(): void
    {
        // A regular-file st_mode (S_IFREG 0o100000) must not leak into the render.
        self::assertSame('0644', (new Permissions(0o100644))->toString());
        // A directory st_mode (S_IFDIR 0o040000) likewise.
        self::assertSame('0755', (new Permissions(0o040755))->toString());
    }

    #[Test]
    public function itDecodesEveryTriad(): void
    {
        // 0o751 => owner rwx, group r-x, public --x
        $permissions = new Permissions(0o751);

        self::assertTrue($permissions->isReadableByOwner());
        self::assertTrue($permissions->isWritableByOwner());
        self::assertTrue($permissions->isExecutableByOwner());

        self::assertTrue($permissions->isReadableByGroup());
        self::assertFalse($permissions->isWritableByGroup());
        self::assertTrue($permissions->isExecutableByGroup());

        self::assertFalse($permissions->isReadableByPublic());
        self::assertFalse($permissions->isWritableByPublic());
        self::assertTrue($permissions->isExecutableByPublic());
    }
}
