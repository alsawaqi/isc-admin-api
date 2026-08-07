<?php

namespace Tests\Unit\Products;

use App\Models\ProductDepartments;
use App\Models\ProductSubDepartment;
use App\Services\ProductHierarchyImportService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class ProductHierarchyImportConcurrencyTest extends TestCase
{
    public function test_database_code_maximum_is_global_within_the_selected_period(): void
    {
        $rows = new Collection([
            (object) ['Products_Sub_Department_Code' => 'SUBDEPT_2026_AUG_SUB_000004'],
            (object) ['Products_Sub_Department_Code' => 'SUBDEPT_2026_AUG_SUB_000019'],
            (object) ['Products_Sub_Department_Code' => 'SUBDEPT_2026_SEP_SUB_000200'],
            (object) ['Products_Sub_Department_Code' => 'SUBDEPT_2026_AUG_MAIN_000001_SUB_000099'],
        ]);

        $maximum = $this->invokePrivate(
            'maximumDatabaseCodeSequence',
            $rows,
            'Products_Sub_Department_Code',
            'sub_department',
            '2026-08',
        );

        $this->assertSame(19, $maximum);
    }

    public function test_compact_leaf_database_code_sequence_is_parsed_for_the_selected_period(): void
    {
        $this->assertSame(
            3066,
            $this->invokePrivate(
                'databaseCodeSequence',
                'SUBSUBDEPT_2026_AUG_SUBSUB_003066',
                'sub_sub_department',
                '2026-08',
            ),
        );
        $this->assertNull($this->invokePrivate(
            'databaseCodeSequence',
            'SUBSUBDEPT_2026_JUL_SUBSUB_003066',
            'sub_sub_department',
            '2026-08',
        ));
    }

    public function test_unchanged_existing_department_still_matches_the_plan(): void
    {
        $model = new ProductDepartments([
            'Product_Department_Name' => 'Pneumatics',
            'Product_Department_Code' => 'LEGACY-DEPT-1',
            'Source_Main_Id' => 'MAIN-0001',
            'Source_Main_Sequence' => 1,
            'Hierarchy_Code_Period' => '2025-07',
        ]);

        $this->invokePrivate('assertDepartmentMatchesPlan', $model, [
            'name' => 'Pneumatics',
            'actual_code' => 'LEGACY-DEPT-1',
            'main_sequence' => 1,
            'code_period' => '2025-07',
            'link_fields' => [],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_department_metadata_populated_after_preview_is_rejected(): void
    {
        $model = new ProductDepartments([
            'Product_Department_Name' => 'Pneumatics',
            'Product_Department_Code' => 'LEGACY-DEPT-1',
            'Source_Main_Id' => 'MAIN-0099',
            'Source_Main_Sequence' => null,
            'Hierarchy_Code_Period' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->invokePrivate('assertDepartmentMatchesPlan', $model, [
            'name' => 'Pneumatics',
            'actual_code' => 'LEGACY-DEPT-1',
            'main_sequence' => 1,
            'code_period' => '2025-07',
            'link_fields' => ['source_main_id', 'source_main_sequence', 'hierarchy_code_period'],
        ]);
    }

    public function test_child_rename_after_preview_is_rejected(): void
    {
        $model = new ProductSubDepartment([
            'Products_Departments_Id' => 10,
            'Sub_Department_Name' => 'Renamed Compressors',
            'Products_Sub_Department_Code' => 'LEGACY-SUB-1',
            'Source_Sub_Sequence' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->invokePrivate(
            'assertChildMatchesPlan',
            $model,
            [
                'name' => 'Air Compressors',
                'actual_code' => 'LEGACY-SUB-1',
                'sub_sequence' => 1,
                'link_fields' => [],
            ],
            'Products_Departments_Id',
            10,
            'Sub_Department_Name',
            'Products_Sub_Department_Code',
            'Source_Sub_Sequence',
            'sub_sequence',
        );
    }

    public function test_child_sequence_populated_after_preview_is_rejected(): void
    {
        $model = new ProductSubDepartment([
            'Products_Departments_Id' => 10,
            'Sub_Department_Name' => 'Air Compressors',
            'Products_Sub_Department_Code' => 'LEGACY-SUB-1',
            'Source_Sub_Sequence' => 9,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->invokePrivate(
            'assertChildMatchesPlan',
            $model,
            [
                'name' => 'Air Compressors',
                'actual_code' => 'LEGACY-SUB-1',
                'sub_sequence' => 1,
                'link_fields' => ['source_sub_sequence'],
            ],
            'Products_Departments_Id',
            10,
            'Sub_Department_Name',
            'Products_Sub_Department_Code',
            'Source_Sub_Sequence',
            'sub_sequence',
        );
    }

    private function invokePrivate(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ProductHierarchyImportService::class, $method);

        return $reflection->invokeArgs(new ProductHierarchyImportService, $arguments);
    }
}
