<?php

namespace Tests\Unit;

use App\Support\CommerceTestDataBackupPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommerceTestDataBackupPlanTest extends TestCase
{
    public function test_generated_backup_path_is_allowed(): void
    {
        self::assertTrue(CommerceTestDataBackupPlan::isAllowedPath(
            '/var/opt/mssql/backup/isc-pre-commerce-reset-20260805T123456Z.bak',
        ));
    }

    #[DataProvider('invalidPaths')]
    public function test_unsafe_or_unexpected_backup_paths_are_rejected(string $path): void
    {
        self::assertFalse(CommerceTestDataBackupPlan::isAllowedPath($path));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['isc-pre-commerce-reset-20260805T123456Z.bak'];
        yield 'parent traversal' => ['/var/opt/mssql/backup/../isc-pre-commerce-reset-20260805T123456Z.bak'];
        yield 'wrong database prefix' => ['/var/opt/mssql/backup/other-pre-commerce-reset-20260805T123456Z.bak'];
        yield 'malformed timestamp' => ['/var/opt/mssql/backup/isc-pre-commerce-reset-2026-08-05.bak'];
        yield 'extra extension' => ['/var/opt/mssql/backup/isc-pre-commerce-reset-20260805T123456Z.bak.tmp'];
        yield 'sql quote' => ["/var/opt/mssql/backup/isc-pre-commerce-reset-20260805T123456Z.bak'; DROP DATABASE [isc];--"];
    }
}
