<?php

namespace Tests\Feature\Products;

use App\Models\ProductDiscount;
use App\Models\ProductMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\FeatureTestCase;

/**
 * Product discounts revamp (product-only + bulk + wide picker):
 * - POST /api/product-discounts/bulk creates one product-targeted row per id
 *   with distinct PDISC codes; missing/soft-deleted ids and fixed >= price
 *   are skipped with reasons; min-selling breaches create + warn.
 * - store/update no longer accept department target types (422).
 * - GET /api/product-discounts/products filters by department tree + search,
 *   excludes soft-deleted, and flags live discounts.
 */
class ProductDiscountBulkTest extends FeatureTestCase
{
    /**
     * Borrow valid category ids from an existing master product so the
     * NOT NULL department columns are satisfied.
     */
    private function refCategoryIds(): ?object
    {
        return DB::table('Products_Master_T')
            ->whereNotNull('Product_Sub_Sub_Department_Id')
            ->orderByDesc('id')
            ->first([
                'Product_Department_Id',
                'Product_Sub_Department_Id',
                'Product_Sub_Sub_Department_Id',
            ]);
    }

    private function ref(): object
    {
        $ref = $this->refCategoryIds();

        if (! $ref) {
            $this->markTestSkipped('No master product with category ids available to borrow from.');
        }

        return $ref;
    }

    private function makeMasterProduct(object $ref, array $overrides = []): ProductMaster
    {
        return ProductMaster::create(array_merge([
            'Product_Code' => 'PDISCTEST_' . uniqid(),

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Name'        => 'Discount Bulk Product ' . uniqid(),
            'Product_Name_Ar'     => 'منتج خصم بالجملة',
            'Product_Description' => 'Bulk discount test product description.',

            'Product_Price' => 10,
            'Product_Stock' => 5,
            'Status'        => 'available',

            'Created_By' => 1,
        ], $overrides));
    }

    private function bulkPayload(array $productIds, array $overrides = []): array
    {
        return array_merge([
            'product_ids'                => $productIds,
            'Product_Discount_Name'      => 'Bulk Test Discount ' . uniqid(),
            'Product_Discount_Type'      => 'percentage',
            'Product_Discount_Value'     => 10,
            'Product_Discount_Is_Active' => true,
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // (a) Bulk happy path
    // ---------------------------------------------------------------

    public function test_bulk_creates_one_row_per_product_with_distinct_codes(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $productA = $this->makeMasterProduct($ref);
        $productB = $this->makeMasterProduct($ref);
        $productC = $this->makeMasterProduct($ref);

        $res = $this->postJson('/api/product-discounts/bulk', $this->bulkPayload(
            [$productA->id, $productB->id, $productC->id],
            [
                'Product_Discount_Type'  => 'percentage',
                'Product_Discount_Value' => 25,
                'Start_Date'             => '2026-01-01T00:00',
                'End_Date'               => '2027-01-01T00:00',
            ]
        ));

        $res->assertStatus(201);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('created_count', 3);
        $this->assertSame([], $res->json('skipped'));

        $createdIds = $res->json('created_ids');
        $this->assertCount(3, $createdIds);

        $discounts = ProductDiscount::query()->whereIn('id', $createdIds)->get();
        $this->assertCount(3, $discounts);

        // Distinct PDISC codes.
        $codes = $discounts->pluck('Product_Discount_Code');
        $this->assertCount(3, $codes->unique());
        foreach ($codes as $code) {
            $this->assertStringStartsWith('PDISC_', $code);
        }

        // Correct fields on every row.
        $this->assertEqualsCanonicalizing(
            [$productA->id, $productB->id, $productC->id],
            $discounts->pluck('Products_Id')->all()
        );
        foreach ($discounts as $discount) {
            $this->assertSame('product', $discount->Target_Type);
            $this->assertNull($discount->Product_Department_Id);
            $this->assertNull($discount->Product_Sub_Department_Id);
            $this->assertNull($discount->Product_Sub_Sub_Department_Id);
            $this->assertSame('percentage', $discount->Product_Discount_Type);
            $this->assertEquals(25.0, (float) $discount->Product_Discount_Value);
            $this->assertTrue((bool) $discount->Product_Discount_Is_Active);
            $this->assertSame('2026-01-01 00:00:00', $discount->Start_Date->format('Y-m-d H:i:s'));
            $this->assertSame('2027-01-01 00:00:00', $discount->End_Date->format('Y-m-d H:i:s'));
        }
    }

    // ---------------------------------------------------------------
    // (b) Missing + soft-deleted ids are skipped, valid ones created
    // ---------------------------------------------------------------

    public function test_bulk_skips_missing_and_soft_deleted_products_but_creates_valid_ones(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $valid = $this->makeMasterProduct($ref);
        $trashed = $this->makeMasterProduct($ref);
        $trashed->delete();

        $missingId = (int) (ProductMaster::withTrashed()->max('id')) + 100000;

        $res = $this->postJson('/api/product-discounts/bulk', $this->bulkPayload(
            [$valid->id, $trashed->id, $missingId]
        ));

        $res->assertStatus(201);
        $res->assertJsonPath('created_count', 1);

        $skipped = collect($res->json('skipped'));
        $this->assertCount(2, $skipped);
        $this->assertEqualsCanonicalizing(
            [$trashed->id, $missingId],
            $skipped->pluck('id')->all()
        );
        foreach ($skipped as $skip) {
            $this->assertSame('Product not found or deleted', $skip['reason']);
        }

        $created = ProductDiscount::query()->findOrFail($res->json('created_ids.0'));
        $this->assertSame($valid->id, (int) $created->Products_Id);
    }

    // ---------------------------------------------------------------
    // (c) Value guards: percentage > 100 -> 422, fixed >= price -> skipped
    // ---------------------------------------------------------------

    public function test_bulk_percentage_over_100_returns_422(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());

        $res = $this->postJson('/api/product-discounts/bulk', $this->bulkPayload(
            [$product->id],
            ['Product_Discount_Type' => 'percentage', 'Product_Discount_Value' => 101]
        ));

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['Product_Discount_Value']);
    }

    public function test_bulk_fixed_discount_at_or_above_price_is_skipped(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $cheap = $this->makeMasterProduct($ref, ['Product_Price' => 10]);
        $expensive = $this->makeMasterProduct($ref, ['Product_Price' => 100]);

        $res = $this->postJson('/api/product-discounts/bulk', $this->bulkPayload(
            [$cheap->id, $expensive->id],
            ['Product_Discount_Type' => 'fixed', 'Product_Discount_Value' => 10]
        ));

        $res->assertStatus(201);
        $res->assertJsonPath('created_count', 1);

        $skip = collect($res->json('skipped'))->firstWhere('id', $cheap->id);
        $this->assertNotNull($skip);
        $this->assertSame('Fixed discount >= product price', $skip['reason']);

        $created = ProductDiscount::query()->findOrFail($res->json('created_ids.0'));
        $this->assertSame($expensive->id, (int) $created->Products_Id);
    }

    // ---------------------------------------------------------------
    // (d) Department target types are no longer accepted
    // ---------------------------------------------------------------

    public function test_store_with_department_target_type_returns_422(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();

        foreach (['department', 'sub_department', 'sub_sub_department'] as $type) {
            $res = $this->postJson('/api/product-discounts', [
                'Product_Discount_Name'          => 'Legacy Target ' . uniqid(),
                'Target_Type'                    => $type,
                'Product_Department_Id'          => $ref->Product_Department_Id,
                'Product_Sub_Department_Id'      => $ref->Product_Sub_Department_Id,
                'Product_Sub_Sub_Department_Id'  => $ref->Product_Sub_Sub_Department_Id,
                'Product_Discount_Type'          => 'percentage',
                'Product_Discount_Value'         => 10,
            ]);

            $res->assertStatus(422);
            $res->assertJsonValidationErrors(['Target_Type']);
        }
    }

    public function test_update_with_department_target_type_returns_422(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $product = $this->makeMasterProduct($ref);

        $discount = ProductDiscount::create([
            'Product_Discount_Code'      => 'PDT_' . uniqid(),
            'Product_Discount_Name'      => 'Update Target Test',
            'Target_Type'                => 'product',
            'Products_Id'                => $product->id,
            'Product_Discount_Type'      => 'percentage',
            'Product_Discount_Value'     => 10,
            'Product_Discount_Is_Active' => 1,
            'Created_By'                 => 1,
            'Created_Date'               => now(),
        ]);

        $res = $this->putJson("/api/product-discounts/{$discount->id}", [
            'Product_Discount_Name'  => 'Update Target Test',
            'Target_Type'            => 'department',
            'Product_Department_Id'  => $ref->Product_Department_Id,
            'Product_Discount_Type'  => 'percentage',
            'Product_Discount_Value' => 10,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['Target_Type']);
        $this->assertSame('product', $discount->fresh()->Target_Type);
    }

    public function test_store_product_target_still_works(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());

        $res = $this->postJson('/api/product-discounts', [
            'Product_Discount_Name'  => 'Single Product Discount ' . uniqid(),
            'Target_Type'            => 'product',
            'Products_Id'            => $product->id,
            'Product_Discount_Type'  => 'fixed',
            'Product_Discount_Value' => 2,
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.Target_Type', 'product');
        $this->assertSame($product->id, (int) $res->json('data.Products_Id'));
    }

    // ---------------------------------------------------------------
    // (e) Product picker
    // ---------------------------------------------------------------

    public function test_picker_filters_by_department_and_search_and_flags_live_discounts(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $token = 'PICKERTOK' . uniqid();

        $withDiscount = $this->makeMasterProduct($ref, [
            'Product_Name' => "Picker {$token} Discounted",
        ]);
        $withoutDiscount = $this->makeMasterProduct($ref, [
            'Product_Name' => "Picker {$token} Plain",
        ]);
        $trashedProduct = $this->makeMasterProduct($ref, [
            'Product_Name' => "Picker {$token} Trashed",
        ]);
        $trashedProduct->delete();

        // Live discount: published + date window spanning now.
        ProductDiscount::create([
            'Product_Discount_Code'      => 'PDT_' . uniqid(),
            'Product_Discount_Name'      => 'Picker Live Discount',
            'Target_Type'                => 'product',
            'Products_Id'                => $withDiscount->id,
            'Product_Discount_Type'      => 'percentage',
            'Product_Discount_Value'     => 15,
            'Product_Discount_Is_Active' => 1,
            'Start_Date'                 => now()->subDays(2)->format('Y-m-d H:i:s'),
            'End_Date'                   => now()->addDays(2)->format('Y-m-d H:i:s'),
            'Created_By'                 => 1,
            'Created_Date'               => now(),
        ]);

        // Expired discount on the other product: must NOT flag it.
        ProductDiscount::create([
            'Product_Discount_Code'      => 'PDT_' . uniqid(),
            'Product_Discount_Name'      => 'Picker Expired Discount',
            'Target_Type'                => 'product',
            'Products_Id'                => $withoutDiscount->id,
            'Product_Discount_Type'      => 'fixed',
            'Product_Discount_Value'     => 1,
            'Product_Discount_Is_Active' => 1,
            'Start_Date'                 => now()->subDays(10)->format('Y-m-d H:i:s'),
            'End_Date'                   => now()->subDays(5)->format('Y-m-d H:i:s'),
            'Created_By'                 => 1,
            'Created_Date'               => now(),
        ]);

        $res = $this->getJson(
            '/api/product-discounts/products'
            . '?department_id=' . $ref->Product_Department_Id
            . '&sub_department_id=' . $ref->Product_Sub_Department_Id
            . '&sub_sub_department_id=' . $ref->Product_Sub_Sub_Department_Id
            . '&search=' . $token
        );

        $res->assertOk();
        $rows = collect($res->json('data'));

        // Search + department filters isolate our fixtures; trashed excluded.
        $this->assertEqualsCanonicalizing(
            [$withDiscount->id, $withoutDiscount->id],
            $rows->pluck('id')->all()
        );

        $discounted = $rows->firstWhere('id', $withDiscount->id);
        $this->assertTrue($discounted['has_active_discount']);
        $this->assertSame('percentage', $discounted['active_discount']['type']);
        $this->assertEquals(15.0, (float) $discounted['active_discount']['value']);

        $plain = $rows->firstWhere('id', $withoutDiscount->id);
        $this->assertFalse($plain['has_active_discount']);
        $this->assertNull($plain['active_discount']);

        // Row shape sanity.
        $this->assertArrayHasKey('Product_Code', $discounted);
        $this->assertArrayHasKey('Product_Sku', $discounted);
        $this->assertArrayHasKey('Product_Price', $discounted);
        $this->assertArrayHasKey('Product_Stock', $discounted);
        $this->assertArrayHasKey('Is_Active', $discounted);
    }

    public function test_picker_search_alone_excludes_other_products(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $token = 'PICKERSOLO' . uniqid();
        $product = $this->makeMasterProduct($ref, [
            'Product_Name' => "Solo {$token} Product",
        ]);
        $this->makeMasterProduct($ref, [
            'Product_Name' => 'Unrelated Picker Product ' . uniqid(),
        ]);

        $res = $this->getJson('/api/product-discounts/products?search=' . $token);

        $res->assertOk();
        $this->assertSame([$product->id], collect($res->json('data'))->pluck('id')->all());
    }

    // ---------------------------------------------------------------
    // (f) Minimum-selling-price warning (non-blocking)
    // ---------------------------------------------------------------

    public function test_bulk_emits_min_selling_warning_but_still_creates(): void
    {
        $this->actingAsAdmin();

        if (! Schema::hasColumn('Products_Master_T', 'Minimum_Selling_Price')) {
            $this->markTestSkipped('Products_Master_T has no Minimum_Selling_Price column.');
        }

        $ref = $this->ref();
        $product = $this->makeMasterProduct($ref, [
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 8,
        ]);

        // 50% off 10 -> 5.000, below the 8.000 floor: create + warn.
        $res = $this->postJson('/api/product-discounts/bulk', $this->bulkPayload(
            [$product->id],
            ['Product_Discount_Type' => 'percentage', 'Product_Discount_Value' => 50]
        ));

        $res->assertStatus(201);
        $res->assertJsonPath('created_count', 1);

        $warning = collect($res->json('warnings'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($warning, 'Expected a minimum-selling-price warning.');
        $this->assertSame(
            'Discounted price 5.000 is below minimum selling price 8.000',
            $warning['warning']
        );

        // Row was still created despite the warning.
        $discount = ProductDiscount::query()->findOrFail($res->json('created_ids.0'));
        $this->assertSame($product->id, (int) $discount->Products_Id);
    }

    public function test_bulk_no_warning_when_discount_stays_at_or_above_floor(): void
    {
        $this->actingAsAdmin();

        if (! Schema::hasColumn('Products_Master_T', 'Minimum_Selling_Price')) {
            $this->markTestSkipped('Products_Master_T has no Minimum_Selling_Price column.');
        }

        $product = $this->makeMasterProduct($this->ref(), [
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 5,
        ]);

        // 10% off 10 -> 9.000, above the 5.000 floor.
        $res = $this->postJson('/api/product-discounts/bulk', $this->bulkPayload(
            [$product->id],
            ['Product_Discount_Type' => 'percentage', 'Product_Discount_Value' => 10]
        ));

        $res->assertStatus(201);
        $res->assertJsonPath('created_count', 1);
        $this->assertSame([], $res->json('warnings'));
    }
}
