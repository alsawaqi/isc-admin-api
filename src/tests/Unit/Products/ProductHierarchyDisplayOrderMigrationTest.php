<?php

namespace Tests\Unit\Products;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductHierarchyDisplayOrderMigrationTest extends TestCase
{
    public function test_migration_defines_separate_sparse_ordering_for_every_hierarchy_level(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_07_100000_add_product_hierarchy_display_order.php';
        $reflection = new ReflectionClass($migration);

        $this->assertSame(1_000_000_000, $reflection->getConstant('ORDER_STEP'));
        $this->assertSame(
            'Product_Hierarchy_Display_Order_State_T',
            $reflection->getConstant('STATE_TABLE'),
        );
        $this->assertSame([
            [
                'table' => 'Products_Departments_T',
                'parent' => null,
                'source' => 'Source_Main_Sequence',
                'index' => 'ux_pd_display_order',
            ],
            [
                'table' => 'Products_Sub_Department_T',
                'parent' => 'Products_Departments_Id',
                'source' => 'Source_Sub_Sequence',
                'index' => 'ux_psd_parent_display_order',
            ],
            [
                'table' => 'Products_Sub_Sub_Department_T',
                'parent' => 'Product_Sub_Department_Id',
                'source' => 'Source_Sub_Sub_Sequence',
                'index' => 'ux_pssd_parent_display_order',
            ],
        ], $reflection->getConstant('LEVELS'));

        $searchIndexes = $reflection->getConstant('SEARCH_INDEXES');
        $this->assertCount(7, $searchIndexes);
        $this->assertSame([
            'idx_pd_display_search_name_ar',
            'idx_psd_display_search_name',
            'idx_psd_display_search_name_ar',
            'idx_psd_parent_name_ar',
            'idx_pssd_display_search_name',
            'idx_pssd_display_search_name_ar',
            'idx_pssd_parent_name_ar',
        ], array_column($searchIndexes, 'name'));
    }

    public function test_migration_contains_sql_server_partition_backfill_and_unique_indexes(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_08_07_100000_add_product_hierarchy_display_order.php',
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('ROW_NUMBER() OVER', $source);
        $this->assertStringContainsString('PARTITION BY', $source);
        $this->assertStringContainsString('ALTER COLUMN [Display_Order] BIGINT NOT NULL', $source);
        $this->assertStringContainsString('CREATE UNIQUE INDEX [ux_pd_display_order]', $source);
        $this->assertStringContainsString('CREATE UNIQUE INDEX [ux_psd_parent_display_order]', $source);
        $this->assertStringContainsString('CREATE UNIQUE INDEX [ux_pssd_parent_display_order]', $source);
        $this->assertStringContainsString('private function createSearchIndexes()', $source);
        $this->assertStringContainsString('private function dropSearchIndexes()', $source);
        $this->assertStringContainsString(' IS NOT NULL', $source);
    }
}
