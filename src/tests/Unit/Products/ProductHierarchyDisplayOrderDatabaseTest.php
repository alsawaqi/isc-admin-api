<?php

namespace Tests\Unit\Products;

use App\Services\ProductHierarchyDisplayOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class ProductHierarchyDisplayOrderDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is not installed.');
        }

        config([
            'database.default' => 'hierarchy_order_test',
            'database.connections.hierarchy_order_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('hierarchy_order_test');
        DB::setDefaultConnection('hierarchy_order_test');

        Schema::create('Products_Departments_T', function (Blueprint $table): void {
            $table->id();
            $table->string('Product_Department_Code')->nullable();
            $table->string('Product_Department_Name')->nullable();
            $table->string('Product_Department_Name_Ar')->nullable();
            $table->unsignedInteger('Source_Main_Sequence')->nullable();
            $table->bigInteger('Display_Order');
            $table->timestamps();
            $table->unique('Display_Order');
        });
        Schema::create('Products_Sub_Department_T', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('Products_Departments_Id');
            $table->string('Products_Sub_Department_Code')->nullable();
            $table->string('Sub_Department_Name')->nullable();
            $table->string('Sub_Department_Name_Ar')->nullable();
            $table->unsignedInteger('Source_Sub_Sequence')->nullable();
            $table->string('Name_Fingerprint')->nullable();
            $table->bigInteger('Display_Order');
            $table->timestamps();
            $table->unique(['Products_Departments_Id', 'Display_Order']);
        });
        Schema::create('Products_Sub_Sub_Department_T', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('Product_Sub_Department_Id');
            $table->string('Product_Sub_Sub_Department_Code')->nullable();
            $table->string('Product_Sub_Sub_Department_Name')->nullable();
            $table->string('Product_Sub_Sub_Department_Name_Ar')->nullable();
            $table->string('Slug')->nullable();
            $table->unsignedInteger('Source_Sub_Sub_Sequence')->nullable();
            $table->string('Name_Fingerprint')->nullable();
            $table->bigInteger('Display_Order');
            $table->timestamps();
            $table->unique(['Product_Sub_Department_Id', 'Display_Order']);
        });
        Schema::create('Product_Hierarchy_Display_Order_State_T', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('Revision')->default(1);
        });
        DB::table('Product_Hierarchy_Display_Order_State_T')->insert(['id' => 1, 'Revision' => 1]);
    }

    protected function tearDown(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            Schema::dropIfExists('Product_Hierarchy_Display_Order_State_T');
            Schema::dropIfExists('Products_Sub_Sub_Department_T');
            Schema::dropIfExists('Products_Sub_Department_T');
            Schema::dropIfExists('Products_Departments_T');
            DB::purge('hierarchy_order_test');
        }

        parent::tearDown();
    }

    public function test_move_before_is_atomic_sparse_and_does_not_change_identity_metadata(): void
    {
        DB::table('Products_Departments_T')->insert([
            $this->department(1, 'DEPT-1', 1, 1),
            $this->department(2, 'DEPT-2', 2, 2),
            $this->department(3, 'DEPT-3', 3, 3),
        ]);

        $ordering = new ProductHierarchyDisplayOrderService;
        $result = $ordering->moveBefore('department', 3, 2, 1);

        $this->assertTrue($result['moved']);
        $this->assertSame(2, $result['revision']);
        $this->assertSame(
            [1, 3, 2],
            DB::table('Products_Departments_T')->orderBy('Display_Order')->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertSame(
            ['DEPT-1', 'DEPT-2', 'DEPT-3'],
            DB::table('Products_Departments_T')->orderBy('id')->pluck('Product_Department_Code')->all(),
        );
        $this->assertSame(
            [1, 2, 3],
            DB::table('Products_Departments_T')->orderBy('id')->pluck('Source_Main_Sequence')->map(fn ($value) => (int) $value)->all(),
        );

        $noOp = $ordering->moveBefore('department', 3, 2, 2);
        $this->assertFalse($noOp['moved']);
        $this->assertSame(2, $noOp['revision']);
    }

    public function test_child_cannot_be_moved_before_an_item_from_another_parent(): void
    {
        DB::table('Products_Departments_T')->insert([
            $this->department(1, 'DEPT-1', 1, 1),
            $this->department(2, 'DEPT-2', 2, 2),
        ]);
        DB::table('Products_Sub_Department_T')->insert([
            [
                'id' => 10,
                'Products_Departments_Id' => 1,
                'Products_Sub_Department_Code' => 'SUB-10',
                'Sub_Department_Name' => 'Sub 10',
                'Display_Order' => ProductHierarchyDisplayOrderService::ORDER_STEP,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 20,
                'Products_Departments_Id' => 2,
                'Products_Sub_Department_Code' => 'SUB-20',
                'Sub_Department_Name' => 'Sub 20',
                'Display_Order' => ProductHierarchyDisplayOrderService::ORDER_STEP,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);

        (new ProductHierarchyDisplayOrderService)->moveBefore('sub_department', 10, 20, 1);
    }

    public function test_child_moves_change_only_display_order_and_revision(): void
    {
        DB::table('Products_Departments_T')->insert(
            $this->department(1, 'DEPT-1', 1, 1),
        );
        DB::table('Products_Sub_Department_T')->insert([
            [
                'id' => 10,
                'Products_Departments_Id' => 1,
                'Products_Sub_Department_Code' => 'SUB-10',
                'Sub_Department_Name' => '  Legacy Sub  ',
                'Source_Sub_Sequence' => 1,
                'Name_Fingerprint' => 'legacy-sub-fingerprint',
                'Display_Order' => ProductHierarchyDisplayOrderService::ORDER_STEP,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 20,
                'Products_Departments_Id' => 1,
                'Products_Sub_Department_Code' => 'SUB-20',
                'Sub_Department_Name' => 'Move Sub',
                'Source_Sub_Sequence' => 2,
                'Name_Fingerprint' => 'move-sub-fingerprint',
                'Display_Order' => ProductHierarchyDisplayOrderService::ORDER_STEP * 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('Products_Sub_Sub_Department_T')->insert([
            [
                'id' => 100,
                'Product_Sub_Department_Id' => 10,
                'Product_Sub_Sub_Department_Code' => 'LEAF-100',
                'Product_Sub_Sub_Department_Name' => '  Legacy Leaf  ',
                'Source_Sub_Sub_Sequence' => 1,
                'Name_Fingerprint' => 'legacy-leaf-fingerprint',
                'Slug' => 'legacy-leaf-slug',
                'Display_Order' => ProductHierarchyDisplayOrderService::ORDER_STEP,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 200,
                'Product_Sub_Department_Id' => 10,
                'Product_Sub_Sub_Department_Code' => 'LEAF-200',
                'Product_Sub_Sub_Department_Name' => 'Move Leaf',
                'Source_Sub_Sub_Sequence' => 2,
                'Name_Fingerprint' => 'move-leaf-fingerprint',
                'Slug' => 'move-leaf-slug',
                'Display_Order' => ProductHierarchyDisplayOrderService::ORDER_STEP * 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $subIdentity = DB::table('Products_Sub_Department_T')
            ->where('id', 20)
            ->first([
                'Products_Departments_Id',
                'Products_Sub_Department_Code',
                'Sub_Department_Name',
                'Source_Sub_Sequence',
                'Name_Fingerprint',
            ]);
        $leafIdentity = DB::table('Products_Sub_Sub_Department_T')
            ->where('id', 200)
            ->first([
                'Product_Sub_Department_Id',
                'Product_Sub_Sub_Department_Code',
                'Product_Sub_Sub_Department_Name',
                'Source_Sub_Sub_Sequence',
                'Name_Fingerprint',
                'Slug',
            ]);

        $ordering = new ProductHierarchyDisplayOrderService;
        $ordering->moveBefore('sub_department', 20, 10, 1);
        $ordering->moveBefore('sub_sub_department', 200, 100, 2);

        $this->assertEquals(
            $subIdentity,
            DB::table('Products_Sub_Department_T')
                ->where('id', 20)
                ->first([
                    'Products_Departments_Id',
                    'Products_Sub_Department_Code',
                    'Sub_Department_Name',
                    'Source_Sub_Sequence',
                    'Name_Fingerprint',
                ]),
        );
        $this->assertEquals(
            $leafIdentity,
            DB::table('Products_Sub_Sub_Department_T')
                ->where('id', 200)
                ->first([
                    'Product_Sub_Department_Id',
                    'Product_Sub_Sub_Department_Code',
                    'Product_Sub_Sub_Department_Name',
                    'Source_Sub_Sub_Sequence',
                    'Name_Fingerprint',
                    'Slug',
                ]),
        );
        $this->assertSame(
            3,
            (int) DB::table('Product_Hierarchy_Display_Order_State_T')
                ->where('id', 1)
                ->value('Revision'),
        );
    }

    /** @return array<string, mixed> */
    private function department(int $id, string $code, int $sourceSequence, int $displaySequence): array
    {
        return [
            'id' => $id,
            'Product_Department_Code' => $code,
            'Product_Department_Name' => 'Department '.$id,
            'Source_Main_Sequence' => $sourceSequence,
            'Display_Order' => $displaySequence,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
