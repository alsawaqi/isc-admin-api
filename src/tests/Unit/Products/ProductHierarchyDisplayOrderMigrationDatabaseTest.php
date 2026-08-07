<?php

namespace Tests\Unit\Products;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

final class ProductHierarchyDisplayOrderMigrationDatabaseTest extends TestCase
{
    private ?string $previousConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is not installed.');
        }

        $this->previousConnection = (string) config('database.default');
        config([
            'database.default' => 'hierarchy_order_migration_test',
            'database.connections.hierarchy_order_migration_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('hierarchy_order_migration_test');
        DB::setDefaultConnection('hierarchy_order_migration_test');

        Schema::create('Products_Departments_T', function (Blueprint $table): void {
            $table->id();
            $table->string('Product_Department_Code', 100)->nullable();
            $table->string('Product_Department_Name');
            $table->string('Product_Department_Name_Ar')->nullable();
            $table->unsignedInteger('Source_Main_Sequence')->nullable();
        });
        Schema::create('Products_Sub_Department_T', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('Products_Departments_Id');
            $table->string('Products_Sub_Department_Code', 100)->nullable();
            $table->string('Sub_Department_Name');
            $table->string('Sub_Department_Name_Ar')->nullable();
            $table->unsignedInteger('Source_Sub_Sequence')->nullable();
        });
        Schema::create('Products_Sub_Sub_Department_T', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('Product_Sub_Department_Id');
            $table->string('Product_Sub_Sub_Department_Code', 100)->nullable();
            $table->string('Product_Sub_Sub_Department_Name');
            $table->string('Product_Sub_Sub_Department_Name_Ar')->nullable();
            $table->unsignedInteger('Source_Sub_Sub_Sequence')->nullable();
        });
    }

    public function test_migration_executes_with_sparse_parent_scoped_order_and_reversible_indexes(): void
    {
        $this->seedHierarchy();
        $migration = require dirname(__DIR__, 3).
            '/database/migrations/2026_08_07_100000_add_product_hierarchy_display_order.php';

        $migration->up();

        $step = 1_000_000_000;
        $this->assertSame(
            [2 => $step, 1 => $step * 2, 3 => $step * 3],
            DB::table('Products_Departments_T')
                ->orderBy('Display_Order')
                ->pluck('Display_Order', 'id')
                ->map(fn ($rank): int => (int) $rank)
                ->all(),
        );
        $this->assertSame(
            [11 => $step, 10 => $step * 2],
            DB::table('Products_Sub_Department_T')
                ->where('Products_Departments_Id', 1)
                ->orderBy('Display_Order')
                ->pluck('Display_Order', 'id')
                ->map(fn ($rank): int => (int) $rank)
                ->all(),
        );
        $this->assertSame(
            [20 => $step],
            DB::table('Products_Sub_Department_T')
                ->where('Products_Departments_Id', 2)
                ->pluck('Display_Order', 'id')
                ->map(fn ($rank): int => (int) $rank)
                ->all(),
        );
        $this->assertSame(
            [101 => $step, 100 => $step * 2],
            DB::table('Products_Sub_Sub_Department_T')
                ->where('Product_Sub_Department_Id', 10)
                ->orderBy('Display_Order')
                ->pluck('Display_Order', 'id')
                ->map(fn ($rank): int => (int) $rank)
                ->all(),
        );
        $this->assertSame(
            [200 => $step],
            DB::table('Products_Sub_Sub_Department_T')
                ->where('Product_Sub_Department_Id', 20)
                ->pluck('Display_Order', 'id')
                ->map(fn ($rank): int => (int) $rank)
                ->all(),
        );
        $this->assertDatabaseHas('Product_Hierarchy_Display_Order_State_T', [
            'id' => 1,
            'Revision' => 1,
        ]);

        foreach ([
            'Products_Departments_T',
            'Products_Sub_Department_T',
            'Products_Sub_Sub_Department_T',
        ] as $table) {
            $displayOrder = collect(Schema::getColumns($table))->firstWhere('name', 'Display_Order');
            $this->assertIsArray($displayOrder);
            $this->assertFalse($displayOrder['nullable']);
        }

        $this->assertContains('ux_pd_display_order', $this->indexNames('Products_Departments_T'));
        $this->assertContains('idx_pd_display_search_name_ar', $this->indexNames('Products_Departments_T'));
        $this->assertContains('ux_psd_parent_display_order', $this->indexNames('Products_Sub_Department_T'));
        $this->assertContains('idx_psd_parent_name_ar', $this->indexNames('Products_Sub_Department_T'));
        $this->assertContains('ux_pssd_parent_display_order', $this->indexNames('Products_Sub_Sub_Department_T'));
        $this->assertContains('idx_pssd_parent_name_ar', $this->indexNames('Products_Sub_Sub_Department_T'));

        $uniqueRankRejected = false;
        try {
            DB::table('Products_Departments_T')->where('id', 1)->update(['Display_Order' => $step]);
        } catch (QueryException) {
            $uniqueRankRejected = true;
        }
        $this->assertTrue($uniqueRankRejected);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('Products_Departments_T', 'Display_Order'));
        $this->assertFalse(Schema::hasColumn('Products_Sub_Department_T', 'Display_Order'));
        $this->assertFalse(Schema::hasColumn('Products_Sub_Sub_Department_T', 'Display_Order'));
        $this->assertFalse(Schema::hasTable('Product_Hierarchy_Display_Order_State_T'));
        $this->assertNotContains('idx_pd_display_search_name_ar', $this->indexNames('Products_Departments_T'));
    }

    protected function tearDown(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            Schema::dropIfExists('Product_Hierarchy_Display_Order_State_T');
            Schema::dropIfExists('Products_Sub_Sub_Department_T');
            Schema::dropIfExists('Products_Sub_Department_T');
            Schema::dropIfExists('Products_Departments_T');
            DB::purge('hierarchy_order_migration_test');

            if ($this->previousConnection !== null) {
                DB::setDefaultConnection($this->previousConnection);
            }
        }

        parent::tearDown();
    }

    private function seedHierarchy(): void
    {
        DB::table('Products_Departments_T')->insert([
            [
                'id' => 1,
                'Product_Department_Code' => 'DEPT-1',
                'Product_Department_Name' => 'Department One',
                'Source_Main_Sequence' => 2,
            ],
            [
                'id' => 2,
                'Product_Department_Code' => 'DEPT-2',
                'Product_Department_Name' => 'Department Two',
                'Source_Main_Sequence' => 1,
            ],
            [
                'id' => 3,
                'Product_Department_Code' => 'DEPT-3',
                'Product_Department_Name' => 'Department Three',
                'Source_Main_Sequence' => null,
            ],
        ]);
        DB::table('Products_Sub_Department_T')->insert([
            [
                'id' => 10,
                'Products_Departments_Id' => 1,
                'Products_Sub_Department_Code' => 'SUB-10',
                'Sub_Department_Name' => 'Sub Ten',
                'Source_Sub_Sequence' => 2,
            ],
            [
                'id' => 11,
                'Products_Departments_Id' => 1,
                'Products_Sub_Department_Code' => 'SUB-11',
                'Sub_Department_Name' => 'Sub Eleven',
                'Source_Sub_Sequence' => 1,
            ],
            [
                'id' => 20,
                'Products_Departments_Id' => 2,
                'Products_Sub_Department_Code' => 'SUB-20',
                'Sub_Department_Name' => 'Sub Twenty',
                'Source_Sub_Sequence' => 1,
            ],
        ]);
        DB::table('Products_Sub_Sub_Department_T')->insert([
            [
                'id' => 100,
                'Product_Sub_Department_Id' => 10,
                'Product_Sub_Sub_Department_Code' => 'LEAF-100',
                'Product_Sub_Sub_Department_Name' => 'Leaf One Hundred',
                'Source_Sub_Sub_Sequence' => 2,
            ],
            [
                'id' => 101,
                'Product_Sub_Department_Id' => 10,
                'Product_Sub_Sub_Department_Code' => 'LEAF-101',
                'Product_Sub_Sub_Department_Name' => 'Leaf One Hundred One',
                'Source_Sub_Sub_Sequence' => 1,
            ],
            [
                'id' => 200,
                'Product_Sub_Department_Id' => 20,
                'Product_Sub_Sub_Department_Code' => 'LEAF-200',
                'Product_Sub_Sub_Department_Name' => 'Leaf Two Hundred',
                'Source_Sub_Sub_Sequence' => 1,
            ],
        ]);
    }

    /** @return array<int, string> */
    private function indexNames(string $table): array
    {
        return array_column(Schema::getIndexes($table), 'name');
    }
}
