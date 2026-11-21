<?php

namespace Tests\Unit\Products;

require_once dirname(__DIR__, 2).'/Support/MinimalXlsxFactory.php';

use App\Models\ProductsBarcodes;
use App\Services\ProductImportService;
use App\Services\ProductImportWorkbook;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\MinimalXlsxFactory;
use Tests\TestCase;

class ProductWorkbookImportTest extends TestCase
{
    private object $actor;

    private array $options;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        foreach ([
            'Products_Departments_T' => ['Product_Department_Name'],
            'Products_Sub_Department_T' => ['Products_Departments_Id', 'Sub_Department_Name'],
            'Products_Sub_Sub_Department_T' => ['Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Name'],
            'Products_Types_Master_T' => ['Product_Types_Name'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $c) {
                    $t->string($c);
                }
            });
        }
        Schema::create('Products_Brands_Master_T', function (Blueprint $t) {
            $t->id();
            foreach (['Product_Brand_Code', 'Products_Brands_Name', 'Products_Brands_Name_Ar', 'Created_By', 'Created_Date'] as $c) {
                $t->string($c)->nullable();
            }$t->timestamps();
        });
        Schema::create('Products_Master_T', function (Blueprint $t) {
            $t->id();
            $t->string('Product_Code')->unique();
            foreach (['Product_Name', 'Product_Name_Ar', 'Product_Description', 'Product_Sku', 'Slug', 'Inhouse_Barcode_Source', 'Status', 'Created_Date'] as $c) {
                $t->string($c)->nullable();
            }
            foreach (['Product_Department_Id', 'Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Id', 'Product_Type_Id', 'Product_Brand_Id', 'Vendor_Id', 'Created_By', 'Product_Stock'] as $c) {
                $t->integer($c)->nullable();
            }
            foreach (['Product_Price', 'Minimum_Selling_Price', 'Product_Cost'] as $c) {
                $t->decimal($c, 18, 3)->nullable();
            }
            $t->boolean('Is_Active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('Product_Supplier_BarCode_T', function (Blueprint $t) {
            $t->id();
            $t->string('Product_Barcode_Code')->unique();
            $t->string('Supplier_Barcode')->unique();
            $t->foreignId('Products_Id')->constrained('Products_Master_T');
            $t->integer('Created_By');
            $t->dateTime('Created_Date');
            $t->timestamps();
        });
        (require database_path('migrations/2026_04_27_230000_create_product_stock_movements_t_table.php'))->up();
        (require database_path('migrations/2026_11_17_100000_create_product_import_jobs.php'))->up();
        DB::table('Products_Departments_T')->insert(['id' => 1, 'Product_Department_Name' => 'Motors']);
        DB::table('Products_Sub_Department_T')->insert(['id' => 2, 'Products_Departments_Id' => 1, 'Sub_Department_Name' => 'AC Motors']);
        DB::table('Products_Sub_Sub_Department_T')->insert(['id' => 3, 'Product_Sub_Department_Id' => 2, 'Product_Sub_Sub_Department_Name' => 'Three Phase']);
        DB::table('Products_Types_Master_T')->insert(['id' => 4, 'Product_Types_Name' => 'Electrical']);
        DB::table('Products_Brands_Master_T')->insert(['id' => 5, 'Products_Brands_Name' => 'ABB']);
        $this->actor = new class
        {
            public int $id = 7;

            public string $User_Name = 'Import tester';

            public bool $brands = true;

            public function can($permission)
            {
                return $this->brands;
            }
        };
        $this->options = ['price_column' => 'max', 'blank_stock' => 'reject', 'product_type_id' => 4, 'category_map' => [], 'brand_map' => []];
    }

    private function workbook(array $changes = [], int $count = 1): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['row' => $i + 2, 'formulas' => [], 'values' => array_replace([
                'srno' => 'SKU-'.$i, 'maingroups' => 'Motors', 'subgroup' => 'AC Motors', 'subsubgroup' => 'Three Phase', 'brandname' => 'Abb',
                'productname' => 'Motor '.$i, 'productdescription' => 'A motor', 'barcode' => 'CODE-'.$i, 'mainstore' => '3', 'cost' => '45.949', 'minsalesrate' => '60', 'maxsalesrate' => '70',
            ], $changes)];
        }

        return ['sheet' => 'Products', 'rows' => $rows];
    }

    private function preview(array $book, ?array $options = null): array
    {
        return app(ProductImportService::class)->preview($book, $options ?? $this->options, $this->actor, 'motors.xlsx', str_repeat('a', 64));
    }

    public function test_preview_and_commit_preserve_values_create_inactive_products_and_replay_safely(): void
    {
        $preview = $this->preview($this->workbook(['maxsalesrate' => '70.123'], 2));
        $this->assertTrue($preview['can_commit']);
        $this->assertSame(0, DB::table('Products_Master_T')->count());
        $service = app(ProductImportService::class);
        $result = $service->commit($preview['preview_token'], $this->actor);
        $this->assertSame(2, $result['created']);
        $this->assertTrue($service->commit($preview['preview_token'], $this->actor)['idempotent']);
        $this->assertSame(2, DB::table('Products_Master_T')->count());
        $product = DB::table('Products_Master_T')->first();
        $this->assertEquals(0, $product->Is_Active);
        $this->assertEquals(3, $product->Product_Stock);
        $this->assertEquals(45.949, $product->Product_Cost);
        $this->assertEquals(70.123, $product->Product_Price);
        $this->assertEquals(60, $product->Minimum_Selling_Price);
        $this->assertSame('SKU-0', $product->Product_Sku);
        $this->assertSame(2, DB::table('Product_Stock_Movements_T')->count());
        $this->assertSame(2, DB::table('Product_Supplier_BarCode_T')->count());
    }

    public function test_blank_stock_is_blocked_unless_zero_is_explicitly_selected_and_cost_stays_null(): void
    {
        $book = $this->workbook(['mainstore' => '', 'cost' => '']);
        $this->assertFalse($this->preview($book)['can_commit']);
        $options = [...$this->options, 'blank_stock' => 'zero'];
        $preview = $this->preview($book, $options);
        $this->assertTrue($preview['can_commit']);
        $this->assertSame(0, $preview['rows'][0]['stock']);
        $this->assertNull($preview['rows'][0]['cost']);
        app(ProductImportService::class)->commit($preview['preview_token'], $this->actor);
        $this->assertSame('out_of_stock', DB::table('Products_Master_T')->value('Status'));
    }

    public function test_missing_price_invalid_stock_precision_and_below_floor_are_blocked(): void
    {
        foreach ([['maxsalesrate' => ''], ['maxsalesrate' => '59.99'], ['maxsalesrate' => '70.1234'], ['mainstore' => '-1'], ['mainstore' => '2.5'], ['cost' => 'oops'], ['minsalesrate' => '']] as $bad) {
            $this->assertFalse($this->preview($this->workbook($bad))['can_commit'], json_encode($bad));
        }
    }

    public function test_category_alias_and_brand_alias_require_explicit_mapping(): void
    {
        $book = $this->workbook(['subgroup' => 'AC Async', 'brandname' => 'ABB Motors']);
        $preview = $this->preview($book);
        $this->assertFalse($preview['can_commit']);
        $options = $this->options;
        $options['category_map'][$preview['categories'][0]['key']] = 3;
        $options['brand_map'][$preview['brands'][0]['key']] = 5;
        $mapped = $this->preview($book, $options);
        $this->assertTrue($mapped['can_commit']);
        $this->assertEquals(2, $mapped['rows'][0]['category']['sub_id']);
    }

    public function test_excel_numeric_storage_noise_is_normalized_without_rounding_real_precision(): void
    {
        $preview = $this->preview($this->workbook(['cost' => '45.948999999999998', 'minsalesrate' => '60.000000000000007', 'maxsalesrate' => '7E1']));
        $this->assertTrue($preview['can_commit']);
        $this->assertSame('45.949', $preview['rows'][0]['cost']);
        $this->assertSame('70.000', $preview['rows'][0]['price']);
        $this->assertFalse($this->preview($this->workbook(['cost' => '45.9492']))['can_commit']);
    }

    public function test_duplicate_workbook_and_existing_barcodes_and_skus_are_blocked(): void
    {
        $this->assertFalse($this->preview($this->workbook(['barcode' => 'SAME'], 2))['can_commit']);
        $this->assertFalse($this->preview($this->workbook(['srno' => 'SAME'], 2))['can_commit']);
        $good = $this->preview($this->workbook());
        app(ProductImportService::class)->commit($good['preview_token'], $this->actor);
        $this->assertFalse($this->preview($this->workbook())['can_commit']);
    }

    public function test_new_brands_are_explicit_permission_checked_and_reused_within_batch(): void
    {
        $book = $this->workbook(['brandname' => 'New Brand'], 2);
        $preview = $this->preview($book);
        $options = $this->options;
        $options['brand_map'][$preview['brands'][0]['key']] = 'new';
        $preview = $this->preview($book, $options);
        $this->actor->brands = false;
        try {
            app(ProductImportService::class)->commit($preview['preview_token'], $this->actor);
            $this->fail('Expected permission rejection');
        } catch (RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->actor->brands = true;
        $result = app(ProductImportService::class)->commit($preview['preview_token'], $this->actor);
        $this->assertSame(1, $result['brands_created']);
        $this->assertSame(2, DB::table('Products_Brands_Master_T')->count());
    }

    public function test_changed_catalogue_invalidates_preview_without_partial_writes(): void
    {
        $preview = $this->preview($this->workbook());
        DB::table('Products_Brands_Master_T')->where('id', 5)->update(['Products_Brands_Name' => 'Changed']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        try {
            app(ProductImportService::class)->commit($preview['preview_token'], $this->actor);
        } finally {
            $this->assertSame(0, DB::table('Products_Master_T')->count());
        }
    }

    public function test_cross_user_and_expired_previews_are_rejected(): void
    {
        $preview = $this->preview($this->workbook());
        $other = clone $this->actor;
        $other->id = 99;
        try {
            app(ProductImportService::class)->commit($preview['preview_token'], $other);
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        DB::table('Product_Import_Jobs_T')->update(['Expires_At' => now()->subMinute()]);
        $this->expectExceptionCode(409);
        app(ProductImportService::class)->commit($preview['preview_token'], $this->actor);
    }

    public function test_database_failure_rolls_back_entire_import(): void
    {
        $preview = $this->preview($this->workbook([], 2));
        $n = 0;
        $event = 'eloquent.creating: '.ProductsBarcodes::class;
        Event::listen($event, function () use (&$n) {
            if (++$n === 2) {
                throw new RuntimeException('Simulated write failure');
            }
        });
        try {
            app(ProductImportService::class)->commit($preview['preview_token'], $this->actor);
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated write failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        foreach (['Products_Master_T', 'Product_Supplier_BarCode_T', 'Product_Stock_Movements_T'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame('pending', DB::table('Product_Import_Jobs_T')->value('Status'));
    }

    public function test_xlsx_reader_uses_headers_preserves_identifiers_and_rejects_formulas_on_import(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'product-xlsx');
        $headers = $values = [];
        $row = $this->workbook(['barcode' => '00123'])['rows'][0]['values'];
        foreach (ProductImportWorkbook::HEADERS as $i => $key) {
            $col = chr(65 + $i);
            $headers[$col.'1'] = $key;
            $values[$col.'2'] = $row[$key];
        }
        $values['I2'] = ['formula' => '1+2', 'value' => '3'];
        try {
            MinimalXlsxFactory::write($file, [1 => $headers, 2 => $values]);
            $parsed = app(ProductImportWorkbook::class)->parse($file);
            $this->assertSame('00123', $parsed['rows'][0]['values']['barcode']);
            $this->assertSame(['mainstore'], $parsed['rows'][0]['formulas']);
            $this->assertFalse($this->preview($parsed)['can_commit']);
        } finally {
            unlink($file);
        }
    }

    public function test_row_types_control_mixed_imports_prices_and_stock_with_no_type_selection(): void
    {
        DB::table('Products_Types_Master_T')->insert(['id' => 8, 'Product_Types_Name' => 'Tools']);
        $book = [...$this->workbook(['mainstore' => ''], 2), 'inhouse_barcodes' => true, 'row_product_types' => true];
        $book['rows'][0]['values']['producttype'] = 'Electrical';
        $book['rows'][1]['values']['producttype'] = 'tools';
        $options = [...$this->options, 'product_type_id' => null, 'price_column' => 'min', 'match_inhouse' => true];
        $preview = $this->preview($book, $options);
        $this->assertTrue($preview['can_commit']);
        $this->assertSame([4, 8], array_column($preview['rows'], 'type_id'));
        $this->assertSame(['70.000', '70.000'], array_column($preview['rows'], 'price'));
        $this->assertSame([0, 0], array_column($preview['rows'], 'stock'));
        $service = app(ProductImportService::class);
        $service->commit($preview['preview_token'], $this->actor);
        $book['rows'][0]['values']['producttype'] = 'Tools';
        $receipt = $service->commit($this->preview($book, $options)['preview_token'], $this->actor);
        $this->assertEquals(8, DB::table('Products_Master_T')->where('Product_Sku', 'SKU-0')->value('Product_Type_Id'));
        $service->rollback($receipt['job_id'], $this->actor);
        $this->assertEquals(4, DB::table('Products_Master_T')->where('Product_Sku', 'SKU-0')->value('Product_Type_Id'));
        foreach (['', 'Unknown'] as $invalid) {
            $book['rows'][0]['values']['producttype'] = $invalid;
            $this->assertFalse($this->preview($book, $options)['rows'][0]['valid']);
        }
    }

    public function test_template_commits_exact_inhouse_code_and_supports_undo(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'product-template');
        $headers = $cells = [];
        $values = $this->workbook(['barcode' => '00017', 'mainstore' => '', 'cost' => ''])['rows'][0]['values'];
        foreach (ProductImportWorkbook::TEMPLATE_HEADERS as $index => $heading) {
            $column = chr(65 + $index);
            $headers[$column.'1'] = $heading;
            $cells[$column.'2'] = $values[ProductImportWorkbook::HEADERS[$index]];
        }
        try {
            MinimalXlsxFactory::write($file, [1 => $headers, 2 => $cells]);
            $book = app(ProductImportWorkbook::class)->parse($file);
            $options = [...$this->options, 'blank_stock' => 'zero'];
            $preview = $this->preview($book, $options);
            $this->assertTrue($preview['can_commit']);
            $service = app(ProductImportService::class);
            $result = $service->commit($preview['preview_token'], $this->actor);
            $product = DB::table('Products_Master_T')->sole();
            $this->assertSame('00017', $product->Inhouse_Barcode_Source);
            $this->assertNull($product->Product_Cost);
            $this->assertEquals(0, $product->Product_Stock);
            $this->assertSame(0, DB::table('Product_Supplier_BarCode_T')->count());
            $book['rows'][0]['values']['srno'] = 'another-sku';
            $this->assertFalse($this->preview($book, $options)['can_commit']);
            $this->assertEquals(1, $service->rollback($result['job_id'], $this->actor)['products_removed']);
        } finally {
            unlink($file);
        }
    }

    public function test_mixed_add_update_and_restore_preview_commit_replay_and_undo(): void
    {
        $service = app(ProductImportService::class);
        $options = [...$this->options, 'match_inhouse' => true];
        $book = [...$this->workbook([], 2), 'inhouse_barcodes' => true];
        $initial = $service->commit($this->preview($book, $options)['preview_token'], $this->actor);
        [$active, $archived] = $initial['product_ids'];
        DB::table('Products_Master_T')->where('id', $active)->update(['Is_Active' => 1]);
        DB::table('Products_Master_T')->where('id', $archived)->update(['Is_Active' => 1, 'deleted_at' => '2026-11-01']);
        $before = DB::table('Products_Master_T')->orderBy('id')->get()->toJson();
        $book = [...$this->workbook(['maxsalesrate' => '80', 'mainstore' => '9', 'cost' => ''], 3), 'inhouse_barcodes' => true];
        $preview = $this->preview($book, $options);
        $this->assertTrue($preview['can_commit']);
        $this->assertSame(['update', 'restore', 'add'], array_column($preview['rows'], 'action'));
        $this->assertSame($before, DB::table('Products_Master_T')->orderBy('id')->get()->toJson());
        $result = $service->commit($preview['preview_token'], $this->actor);
        $this->assertSame([1, 1, 1], [$result['created'], $result['updated'], $result['restored']]);
        $this->assertTrue($service->commit($preview['preview_token'], $this->actor)['idempotent']);
        $item = DB::table('Products_Master_T')->where('id', $archived)->first();
        $this->assertNull($item->deleted_at);
        $this->assertFalse((bool) $item->Is_Active);
        $this->assertTrue((bool) DB::table('Products_Master_T')->where('id', $active)->value('Is_Active'));
        $this->assertEquals(80, $item->Product_Price);
        $this->assertNull($item->Product_Cost);
        $this->assertEquals(9, $item->Product_Stock);
        $this->assertEquals(6, DB::table('Product_Stock_Movements_T')->where('Products_Id', $archived)->where('Movement_Type', 'set')->value('Quantity_Delta'));
        $this->assertSame(2, $service->rollback($result['job_id'], $this->actor)['updates_reversed']);
        $this->assertSame($before, DB::table('Products_Master_T')->orderBy('id')->get()->toJson());
        $this->assertEquals(2, DB::table('Product_Stock_Movements_T')->count());
    }

    public function test_changed_update_target_requires_new_preview_and_later_stock_edits_block_undo(): void
    {
        $service = app(ProductImportService::class);
        $options = [...$this->options, 'match_inhouse' => true];
        $book = [...$this->workbook(), 'inhouse_barcodes' => true];
        $service->commit($this->preview($book, $options)['preview_token'], $this->actor);
        $preview = $this->preview($book, $options);
        DB::table('Products_Master_T')->update(['Product_Stock' => 20]);
        try {
            $service->commit($preview['preview_token'], $this->actor);
            $this->fail('Stale stock preview must fail');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $result = $service->commit($this->preview($book, $options)['preview_token'], $this->actor);
        DB::table('Products_Master_T')->update(['Product_Stock' => 18]);
        $this->expectExceptionCode(409);
        $service->rollback($result['job_id'], $this->actor);
    }

    public function test_undo_removes_only_its_batch_and_allows_reimport_with_a_fresh_preview(): void
    {
        $service = app(ProductImportService::class);
        $first = $service->commit($this->preview($this->workbook(['srno' => 'KEEP', 'barcode' => 'KEEP']))['preview_token'], $this->actor);
        $book = $this->workbook(['brandname' => 'New Brand'], 2);
        $preview = $this->preview($book);
        $options = [...$this->options, 'brand_map' => [$preview['brands'][0]['key'] => 'new']];
        $preview = $this->preview($book, $options);
        $batch = $service->commit($preview['preview_token'], $this->actor);
        $result = $service->rollback($batch['job_id'], $this->actor);
        $this->assertSame(2, $result['products_removed']);
        $this->assertSame(1, $result['brands_removed']);
        $this->assertTrue($service->rollback($batch['job_id'], $this->actor)['idempotent']);
        $this->assertEquals($first['product_ids'], DB::table('Products_Master_T')->pluck('id')->all());
        $this->assertSame(1, DB::table('Product_Supplier_BarCode_T')->count());
        $this->assertSame(1, DB::table('Product_Stock_Movements_T')->count());
        $this->assertSame('rolled_back', DB::table('Product_Import_Jobs_T')->where('id', $batch['job_id'])->value('Status'));
        try {
            $service->commit($preview['preview_token'], $this->actor);
            $this->fail('An undone import must not be replayed');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertTrue($this->preview($book, $options)['can_commit']);
    }

    public function test_undo_blocks_changed_active_or_removed_products_without_partial_deletion(): void
    {
        $service = app(ProductImportService::class);
        $batch = $service->commit($this->preview($this->workbook([], 2))['preview_token'], $this->actor);
        $id = $batch['product_ids'][1];
        $original = (array) DB::table('Products_Master_T')->where('id', $id)->first();
        foreach ([['Product_Name' => 'Edited'], ['Is_Active' => true], ['Product_Stock' => 9], ['deleted_at' => now()]] as $change) {
            DB::table('Products_Master_T')->where('id', $id)->update($change);
            try {
                $service->rollback($batch['job_id'], $this->actor);
                $this->fail('Expected change protection');
            } catch (RuntimeException $e) {
                $this->assertSame(409, $e->getCode());
            }
            $this->assertSame(2, DB::table('Products_Master_T')->count());
            $this->assertSame(2, DB::table('Product_Supplier_BarCode_T')->count());
            DB::table('Products_Master_T')->where('id', $id)->update($original);
        }
    }

    public function test_undo_blocks_order_and_unconstrained_related_records_and_added_stock_movements(): void
    {
        $service = app(ProductImportService::class);
        $batch = $service->commit($this->preview($this->workbook([], 2))['preview_token'], $this->actor);
        Schema::create('OrderLinks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('custom_product_ref')->constrained('Products_Master_T');
        });
        Schema::create('ImageLinks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('Product_Id');
        });
        foreach ([['OrderLinks', 'custom_product_ref'], ['ImageLinks', 'Product_Id']] as [$table, $column]) {
            DB::table($table)->insert([$column => $batch['product_ids'][1]]);
            try {
                $service->rollback($batch['job_id'], $this->actor);
                $this->fail('Expected relationship protection');
            } catch (RuntimeException $e) {
                $this->assertSame(409, $e->getCode());
            }
            $this->assertSame(2, DB::table('Products_Master_T')->count());
            DB::table($table)->delete();
        }
        DB::table('Product_Stock_Movements_T')->where('Products_Id', $batch['product_ids'][0])->update(['Notes' => 'Edited ledger']);
        $this->expectExceptionCode(409);
        $service->rollback($batch['job_id'], $this->actor);
    }

    public function test_undo_retains_a_new_brand_used_by_another_product(): void
    {
        $service = app(ProductImportService::class);
        $book = $this->workbook(['brandname' => 'New Brand']);
        $preview = $this->preview($book);
        $options = [...$this->options, 'brand_map' => [$preview['brands'][0]['key'] => 'new']];
        $batch = $service->commit($this->preview($book, $options)['preview_token'], $this->actor);
        $service->commit($this->preview($this->workbook(['brandname' => 'New Brand', 'srno' => 'SECOND', 'barcode' => 'SECOND']))['preview_token'], $this->actor);
        $result = $service->rollback($batch['job_id'], $this->actor);
        $this->assertSame(0, $result['brands_removed']);
        $this->assertSame(1, $result['brands_retained']);
        $this->assertSame(2, DB::table('Products_Brands_Master_T')->count());
        $this->assertSame(1, DB::table('Products_Master_T')->count());
    }

    public function test_undo_rejects_another_user_and_legacy_imports_without_a_snapshot(): void
    {
        $service = app(ProductImportService::class);
        $batch = $service->commit($this->preview($this->workbook())['preview_token'], $this->actor);
        $other = clone $this->actor;
        $other->id = 99;
        try {
            $service->rollback($batch['job_id'], $other);
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        DB::table('Product_Import_Jobs_T')->where('id', $batch['job_id'])->update(['Payload' => '{}']);
        $this->expectExceptionCode(409);
        $service->rollback($batch['job_id'], $this->actor);
    }

    public function test_database_failure_during_undo_restores_every_product_and_child_record(): void
    {
        $service = app(ProductImportService::class);
        $batch = $service->commit($this->preview($this->workbook([], 2))['preview_token'], $this->actor);
        DB::unprepared('CREATE TRIGGER fail_undo BEFORE DELETE ON Products_Master_T WHEN old.id = '.(int) $batch['product_ids'][1]." BEGIN SELECT RAISE(ABORT, 'Simulated delete failure'); END");
        try {
            $service->rollback($batch['job_id'], $this->actor);
            $this->fail();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('Simulated delete failure', $e->getMessage());
        }
        foreach (['Products_Master_T', 'Product_Supplier_BarCode_T', 'Product_Stock_Movements_T'] as $table) {
            $this->assertSame(2, DB::table($table)->count());
        }
        $this->assertSame('committed', DB::table('Product_Import_Jobs_T')->value('Status'));
    }

    public function test_http_routes_require_authentication_and_product_master_permission(): void
    {
        $this->getJson('/api/product-import/lookups')->assertUnauthorized();
        $user = \Mockery::mock(\App\Models\User::class)->makePartial();
        $user->forceFill(['id' => 7]);
        $user->shouldReceive('checkPermissionTo')->andReturn(false);
        Gate::define('product master', fn () => false);
        $this->actingAs($user, 'sanctum')->getJson('/api/product-import/lookups')->assertForbidden();
        $this->postJson('/api/product-import/commit', ['preview_token' => '123'])->assertForbidden();
        $this->postJson('/api/product-import/rollback', ['job_id' => 1, 'confirm' => true])->assertForbidden();
    }

    public function test_http_preview_commit_and_history_use_server_owned_payloads(): void
    {
        $user = \Mockery::mock(\App\Models\User::class)->makePartial();
        $user->forceFill(['id' => 7, 'User_Name' => 'Tester']);
        $user->shouldReceive('checkPermissionTo')->andReturn(false);
        Gate::define('product master', fn () => true);
        Gate::define('product brands', fn () => true);
        $this->actingAs($user, 'sanctum');
        $file = tempnam(sys_get_temp_dir(), 'product-upload');
        $headers = $values = [];
        $row = $this->workbook()['rows'][0]['values'];
        foreach (ProductImportWorkbook::HEADERS as $i => $key) {
            $col = chr(65 + $i);
            $headers[$col.'1'] = $key;
            $values[$col.'2'] = $row[$key];
        }
        try {
            MinimalXlsxFactory::write($file, [1 => $headers, 2 => $values]);
            $response = $this->post('/api/product-import/preview', ['file' => new UploadedFile($file, 'motors.xlsx', null, null, true), 'options' => json_encode($this->options)], ['Accept' => 'application/json']);
            $response->assertOk()->assertJsonPath('data.can_commit', true);
            $token = $response->json('data.preview_token');
            $this->postJson('/api/product-import/commit', ['preview_token' => $token, 'rows' => [['price' => 0]]])->assertOk()->assertJsonPath('data.created', 1);
            $this->assertEquals(70, DB::table('Products_Master_T')->value('Product_Price'));
            $this->getJson('/api/product-import/history')->assertOk()->assertJsonCount(1, 'data');
            $id = DB::table('Product_Import_Jobs_T')->value('id');
            $this->postJson('/api/product-import/rollback', ['job_id' => $id])->assertUnprocessable();
            $this->postJson('/api/product-import/rollback', ['job_id' => $id, 'confirm' => true])->assertOk()->assertJsonPath('data.products_removed', 1);
            $this->getJson('/api/product-import/history')->assertOk()->assertJsonPath('data.0.Status', 'rolled_back');
        } finally {
            unlink($file);
        }
    }
}
