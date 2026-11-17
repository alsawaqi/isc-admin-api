<?php

namespace Tests\Unit\Products;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpecificationVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('Products_Sub_Sub_Department_T', fn (Blueprint $t) => $t->id());
        DB::table('Products_Sub_Sub_Department_T')->insert(['id' => 1]);
        Schema::create('Product_Specification_Description_T', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_sub_sub_department_id');
            $t->string('Product_Specification_Description_Name');
            $t->string('input_type')->default('text');
            $t->text('options_json')->nullable();
            $t->boolean('is_active')->default(false);
            $t->boolean('is_required')->default(false);
            $t->integer('sort_order')->nullable();
            $t->integer('Created_By')->nullable();
            $t->timestamps();
        });
        Schema::create('Product_Specification_Value_T', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_specification_description_id');
            $t->string('value');
            $t->string('normalized_value')->nullable();
            $t->string('slug')->nullable();
            $t->timestamps();
        });
        foreach ([true, false] as $active) {
            DB::table('Product_Specification_Description_T')->insert(['product_sub_sub_department_id' => 1, 'Product_Specification_Description_Name' => $active ? 'Legacy active' : 'Legacy inactive', 'is_active' => $active]);
        }
        (require database_path('migrations/2026_11_17_120000_separate_specification_table_visibility.php'))->up();
        $user = \Mockery::mock(\App\Models\User::class)->makePartial();
        $user->forceFill(['id' => 7]);
        $user->shouldReceive('checkPermissionTo')->andReturn(false);
        $this->actingAs($user, 'sanctum');
    }

    public function test_migration_preserves_existing_visibility_in_both_places(): void
    {
        $this->getJson('/api/product-specification?sub_sub_category_id=1')->assertOk()
            ->assertJsonPath('0.show_in_filters', true)->assertJsonPath('0.show_in_table', true)
            ->assertJsonPath('1.show_in_filters', false)->assertJsonPath('1.show_in_table', false);
    }

    public function test_create_and_read_support_all_four_visibility_combinations(): void
    {
        $specs = [];
        foreach ([[true, false], [false, true], [true, true], [false, false]] as $i => [$filters,$table]) {
            $specs[] = ['name' => 'Test '.$i, 'input_type' => 'select', 'show_in_filters' => $filters, 'show_in_table' => $table, 'values' => ['A', 'B']];
        }
        $this->postJson('/api/product-specifications/bulk', ['sub_sub_category_id' => 1, 'specs' => $specs])->assertCreated();
        $rows = $this->getJson('/api/product-specification?sub_sub_category_id=1')->assertOk()->json();
        foreach ($specs as $i => $expected) {
            $this->assertSame($expected['show_in_filters'], $rows[$i + 2]['show_in_filters']);
            $this->assertSame($expected['show_in_table'], $rows[$i + 2]['show_in_table']);
            $this->assertCount(2, $rows[$i + 2]['values']);
        }
    }

    public function test_update_keeps_flags_independent_and_preserves_omitted_fields(): void
    {
        $base = ['id' => 1, 'name' => 'Legacy active', 'input_type' => 'text', 'values' => []];
        foreach ([[true, false], [false, true], [false, false], [true, true]] as [$filters,$table]) {
            $this->putJson('/api/product-specifications/bulk', ['sub_sub_category_id' => 1, 'specs' => [[...$base, 'show_in_filters' => $filters, 'show_in_table' => $table]]])->assertOk();
            $this->getJson('/api/product-specification?sub_sub_category_id=1')->assertJsonPath('0.show_in_filters', $filters)->assertJsonPath('0.show_in_table', $table);
        }
        $this->putJson('/api/product-specifications/bulk', ['sub_sub_category_id' => 1, 'specs' => [[...$base, 'show_in_table' => false]]])->assertOk();
        $this->putJson('/api/product-specifications/bulk', ['sub_sub_category_id' => 1, 'specs' => [$base]])->assertOk();
        $this->getJson('/api/product-specification?sub_sub_category_id=1')->assertJsonPath('0.show_in_filters', true)->assertJsonPath('0.show_in_table', false);
    }

    public function test_legacy_create_and_boolean_validation_remain_supported(): void
    {
        $spec = ['name' => 'Legacy API', 'input_type' => 'text', 'is_active' => true, 'values' => []];
        $this->postJson('/api/product-specifications/bulk', ['sub_sub_category_id' => 1, 'specs' => [$spec]])->assertCreated();
        $this->getJson('/api/product-specification?sub_sub_category_id=1')->assertJsonPath('2.show_in_filters', true)->assertJsonPath('2.show_in_table', true);
        $this->postJson('/api/product-specifications/bulk', ['sub_sub_category_id' => 1, 'specs' => [[...$spec, 'show_in_table' => 'invalid']]])->assertUnprocessable();
    }
}
