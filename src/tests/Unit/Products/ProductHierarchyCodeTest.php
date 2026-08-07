<?php

namespace Tests\Unit\Products;

use App\Support\ProductHierarchyCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductHierarchyCodeTest extends TestCase
{
    public function test_it_generates_compact_database_codes(): void
    {
        $this->assertSame(
            'DEPT_2025_JUL_MAIN_000001',
            ProductHierarchyCode::department('2025-07', 1),
        );
        $this->assertSame(
            'SUBDEPT_2025_JUL_SUB_000002',
            ProductHierarchyCode::subDepartment('2025-07', 2),
        );
        $this->assertSame(
            'SUBSUBDEPT_2025_JUL_SUBSUB_000003',
            ProductHierarchyCode::subSubDepartment('2025-07', 3),
        );
    }

    public function test_it_generates_parent_linked_export_codes(): void
    {
        $this->assertSame(
            'SUBDEPT_2025_JUL_MAIN_000001_SUB_000002',
            ProductHierarchyCode::exportSubDepartment('2025-07', 1, 2),
        );
        $this->assertSame(
            'SUBSUBDEPT_2025_JUL_MAIN_000001_SUB_000002_SUBSUB_000003',
            ProductHierarchyCode::exportSubSubDepartment('2025-07', 1, 2, 3),
        );
    }

    public function test_it_parses_compact_database_codes(): void
    {
        $this->assertSame(
            ['period' => '2026-08', 'sequence' => 42, 'sequence_code' => '000042'],
            ProductHierarchyCode::parseSubDepartment('SUBDEPT_2026_AUG_SUB_000042'),
        );
        $this->assertSame(
            ['period' => '2026-08', 'sequence' => 99, 'sequence_code' => '000099'],
            ProductHierarchyCode::parseSubSubDepartment('SUBSUBDEPT_2026_AUG_SUBSUB_000099'),
        );
    }

    #[DataProvider('invalidStoredChildCodes')]
    public function test_invalid_database_child_codes_are_rejected(string $type, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);

        if ($type === 'sub') {
            ProductHierarchyCode::parseSubDepartment($code);

            return;
        }

        ProductHierarchyCode::parseSubSubDepartment($code);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidStoredChildCodes(): iterable
    {
        yield 'hierarchical sub code is not a storage code' => [
            'sub',
            'SUBDEPT_2026_AUG_MAIN_000001_SUB_000001',
        ];
        yield 'invalid month' => ['sub', 'SUBDEPT_2026_ABC_SUB_000001'];
        yield 'zero sub sequence' => ['sub', 'SUBDEPT_2026_AUG_SUB_000000'];
        yield 'lowercase sub prefix' => ['sub', 'subdept_2026_AUG_SUB_000001'];
        yield 'hierarchical leaf code is not a storage code' => [
            'leaf',
            'SUBSUBDEPT_2026_AUG_MAIN_000001_SUB_000001_SUBSUB_000001',
        ];
        yield 'invalid leaf month' => ['leaf', 'SUBSUBDEPT_2026_ABC_SUBSUB_000001'];
        yield 'zero leaf sequence' => ['leaf', 'SUBSUBDEPT_2026_AUG_SUBSUB_000000'];
    }

    public function test_main_id_is_the_department_sequence(): void
    {
        $identity = ProductHierarchyCode::parseMainId('MAIN-0035');

        $this->assertSame('MAIN-0035', $identity['source_id']);
        $this->assertSame(35, $identity['sequence']);
        $this->assertSame('000035', $identity['sequence_code']);
        $this->assertSame(
            'DEPT_2026_AUG_MAIN_000035',
            ProductHierarchyCode::department('2026-08', $identity['sequence']),
        );
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_or_out_of_range_periods_are_rejected(string $period): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductHierarchyCode::normalizePeriod($period);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPeriods(): iterable
    {
        yield 'year below supported range' => ['1999-12'];
        yield 'year above supported range' => ['2100-01'];
        yield 'month without zero padding' => ['2025-7'];
        yield 'invalid month' => ['2025-13'];
        yield 'wrong separator' => ['2025/07'];
    }

    #[DataProvider('invalidMainIds')]
    public function test_invalid_main_ids_are_rejected(string $mainId): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductHierarchyCode::parseMainId($mainId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMainIds(): iterable
    {
        yield 'lowercase prefix' => ['main-0001'];
        yield 'wrong separator' => ['MAIN 0001'];
        yield 'too few digits' => ['MAIN-001'];
        yield 'zero sequence' => ['MAIN-0000'];
        yield 'sequence overflow' => ['MAIN-1000000'];
    }
}
