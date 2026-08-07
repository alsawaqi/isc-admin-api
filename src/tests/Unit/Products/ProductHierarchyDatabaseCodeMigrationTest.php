<?php

namespace Tests\Unit\Products;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class ProductHierarchyDatabaseCodeMigrationTest extends TestCase
{
    public function test_recode_plan_uses_deterministic_global_child_sequences(): void
    {
        $plan = $this->buildPlan($this->validRows());
        $codes = [];
        foreach ($plan as $item) {
            $codes[$item['entity_type'].':'.$item['entity_id']] = $item['target_code'];
        }

        $this->assertSame([
            'department:5' => 'DEPT_2026_AUG_MAIN_000001',
            'department:10' => 'DEPT_2026_AUG_MAIN_000002',
            'sub_department:15' => 'SUBDEPT_2026_AUG_SUB_000001',
            'sub_department:20' => 'SUBDEPT_2026_AUG_SUB_000002',
            'sub_sub_department:25' => 'SUBSUBDEPT_2026_AUG_SUBSUB_000001',
            'sub_sub_department:30' => 'SUBSUBDEPT_2026_AUG_SUBSUB_000002',
        ], $codes);
    }

    public function test_recode_plan_rejects_a_department_outside_august_2026(): void
    {
        $rows = $this->validRows();
        $rows['departments'][0]->Hierarchy_Code_Period = '2026-09';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must have Hierarchy_Code_Period 2026-08');

        $this->buildPlan($rows);
    }

    /**
     * @param  array<string, array<int, object>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildPlan(array $rows): array
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_07_000000_recode_product_hierarchy_database_codes.php';
        $method = new ReflectionMethod($migration, 'buildPlan');

        return $method->invoke($migration, $rows);
    }

    /** @return array<string, array<int, object>> */
    private function validRows(): array
    {
        return [
            'departments' => [
                (object) [
                    'id' => 10,
                    'Product_Department_Code' => 'old-department-2',
                    'Source_Main_Id' => 'MAIN-0002',
                    'Source_Main_Sequence' => 2,
                    'Hierarchy_Code_Period' => '2026-08',
                ],
                (object) [
                    'id' => 5,
                    'Product_Department_Code' => 'old-department-1',
                    'Source_Main_Id' => 'MAIN-0001',
                    'Source_Main_Sequence' => 1,
                    'Hierarchy_Code_Period' => '2026-08',
                ],
            ],
            'sub_departments' => [
                (object) [
                    'id' => 20,
                    'Products_Departments_Id' => 10,
                    'Products_Sub_Department_Code' => 'old-sub-2',
                    'Source_Sub_Sequence' => 1,
                ],
                (object) [
                    'id' => 15,
                    'Products_Departments_Id' => 5,
                    'Products_Sub_Department_Code' => 'old-sub-1',
                    'Source_Sub_Sequence' => 1,
                ],
            ],
            'sub_sub_departments' => [
                (object) [
                    'id' => 30,
                    'Product_Sub_Department_Id' => 20,
                    'Product_Sub_Sub_Department_Code' => 'old-leaf-2',
                    'Source_Sub_Sub_Sequence' => 1,
                ],
                (object) [
                    'id' => 25,
                    'Product_Sub_Department_Id' => 15,
                    'Product_Sub_Sub_Department_Code' => 'old-leaf-1',
                    'Source_Sub_Sub_Sequence' => 1,
                ],
            ],
        ];
    }
}
