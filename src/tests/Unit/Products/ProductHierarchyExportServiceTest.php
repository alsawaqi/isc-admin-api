<?php

namespace Tests\Unit\Products;

use App\Services\ProductHierarchyExportService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProductHierarchyExportServiceTest extends TestCase
{
    public function test_export_row_uses_parent_linked_codes_instead_of_database_codes(): void
    {
        $item = (object) [
            'department_id' => 10,
            'source_main_id' => 'MAIN-0007',
            'source_main_sequence' => 7,
            'hierarchy_code_period' => '2026-08',
            'department_name' => 'Pneumatics',
            'sub_department_id' => 20,
            'source_sub_sequence' => 3,
            'sub_department_name' => 'Air Compressors',
            'sub_sub_department_id' => 30,
            'source_sub_sub_sequence' => 4,
            'sub_sub_department_name' => 'Rotary Screw Compressors',
        ];

        $method = new ReflectionMethod(ProductHierarchyExportService::class, 'hierarchyRow');
        $row = $method->invoke(new ProductHierarchyExportService, $item, true, true);

        $this->assertSame([
            '7',
            'MAIN-0007',
            'Pneumatics',
            'DEPT_2026_AUG_MAIN_000007',
            'Air Compressors',
            'SUBDEPT_2026_AUG_MAIN_000007_SUB_000003',
            'Rotary Screw Compressors',
            'SUBSUBDEPT_2026_AUG_MAIN_000007_SUB_000003_SUBSUB_000004',
        ], $row);
    }
}
