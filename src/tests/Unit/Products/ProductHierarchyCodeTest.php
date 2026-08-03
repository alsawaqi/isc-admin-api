<?php

namespace Tests\Unit\Products;

use App\Support\ProductHierarchyCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductHierarchyCodeTest extends TestCase
{
    public function test_it_generates_the_requested_inherited_codes(): void
    {
        $this->assertSame(
            'DEPT_2025_JUL_MAIN_000001',
            ProductHierarchyCode::department('2025-07', 1),
        );
        $this->assertSame(
            'SUBDEPT_2025_JUL_MAIN_000001_SUB_000001',
            ProductHierarchyCode::subDepartment('2025-07', 1, 1),
        );
        $this->assertSame(
            'SUBSUBDEPT_2025_JUL_MAIN_000001_SUB_000001_SUBSUB_000001',
            ProductHierarchyCode::subSubDepartment('2025-07', 1, 1, 1),
        );
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
