<?php

namespace Tests\Feature\Products;

use App\Models\ProductBulkPrice;
use App\Models\ProductMaster;
use App\Models\ProductTemporary;
use App\Models\ProductTemporaryBulkPrice;
use App\Models\ProductVendorRequest;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/**
 * Quantity-tier bulk pricing (admin API):
 * - POST /api/productmaster/{id}/bulk-prices replace-set endpoint
 *   (happy path / overlap 422 / floor 422 / clear-all)
 * - GET /api/productmaster/{id} returns ordered tiers
 * - temp-product approval copies temp tiers to master
 * - vendor update requests with a 'bulk_prices' key
 */
class ProductBulkPriceTest extends FeatureTestCase
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

    private function makeVendor(): VendorMaster
    {
        return VendorMaster::create([
            'Vendor_Code'     => 'BPTEST_' . uniqid(),
            'Vendor_Name'     => 'Bulk Price Test Vendor',
            'Approval_Status' => 'approved',
            'Status'          => 'active',
            'Is_Active'       => 1,
        ]);
    }

    private function makeMasterProduct(object $ref, ?float $floor = null, ?int $vendorId = null): ProductMaster
    {
        return ProductMaster::create([
            'Product_Code' => 'PRODBP_' . uniqid(),

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Name'        => 'Bulk Price Product ' . uniqid(),
            'Product_Name_Ar'     => 'منتج سعر الجملة',
            'Product_Description' => 'Bulk price test product description.',

            'Product_Price'         => 10,
            'Minimum_Selling_Price' => $floor,
            'Product_Stock'         => 50,
            'Status'                => 'available',

            'Vendor_Id'  => $vendorId,
            'Created_By' => 1,
        ]);
    }

    private function makeTempProduct(VendorMaster $vendor, object $ref): ProductTemporary
    {
        return ProductTemporary::create([
            'Vendor_Id'         => $vendor->id,
            'Temp_Product_Code' => 'TMPBP_' . uniqid(),

            'Product_Name'    => 'Bulk Price Temp Product ' . uniqid(),
            'Product_Name_Ar' => 'منتج مؤقت لسعر الجملة',
            'Description'     => 'Bulk price temp product description.',

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Price' => 10,
            'Product_Stock' => 5,

            'Submission_Status' => 'pending',
            'Submitted_At'      => now(),
        ]);
    }

    private function makeTempTier(ProductTemporary $temp, int $min, ?int $max, float $price): ProductTemporaryBulkPrice
    {
        return ProductTemporaryBulkPrice::create([
            'Products_Temporary_Id' => $temp->id,
            'Min_Qty'               => $min,
            'Max_Qty'               => $max,
            'Unit_Price'            => $price,
        ]);
    }

    private function makeMasterTier(ProductMaster $product, int $min, ?int $max, float $price): ProductBulkPrice
    {
        return ProductBulkPrice::create([
            'Products_Id' => $product->id,
            'Min_Qty'     => $min,
            'Max_Qty'     => $max,
            'Unit_Price'  => $price,
        ]);
    }

    private function makeUpdateRequest(ProductMaster $product, array $changes): ProductVendorRequest
    {
        return ProductVendorRequest::create([
            'Products_Id'            => $product->id,
            'Vendor_Id'              => $product->Vendor_Id,
            'Request_Type'           => 'approved_update',
            'Status'                 => 'pending',
            'Requested_Changes_Json' => $changes,
            'Action_By_Role'         => 'vendor',
            'Action_At'              => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // (a) Set-tiers endpoint
    // ---------------------------------------------------------------

    public function test_set_bulk_prices_happy_path_replaces_the_set(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());

        // Pre-existing tier that must be replaced away.
        $this->makeMasterTier($product, 2, 3, 9.0);

        $res = $this->postJson("/api/productmaster/{$product->id}/bulk-prices", [
            'tiers' => [
                ['min_qty' => 20, 'max_qty' => 50, 'unit_price' => 5.5],
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
                ['min_qty' => 51, 'max_qty' => null, 'unit_price' => 5.0],
            ],
        ]);

        $res->assertOk();

        $saved = collect($res->json('data'));
        $this->assertCount(3, $saved);

        // Returned ordered by Min_Qty regardless of submit order.
        $this->assertSame([5, 20, 51], $saved->pluck('Min_Qty')->map(fn ($v) => (int) $v)->all());
        $this->assertNull($saved->last()['Max_Qty']);

        // The old tier is gone; only the new set remains.
        $rows = ProductBulkPrice::query()->where('Products_Id', $product->id)->get();
        $this->assertCount(3, $rows);
        $this->assertFalse($rows->contains(fn ($r) => (int) $r->Min_Qty === 2));
    }

    public function test_set_bulk_prices_rejects_overlapping_ranges_with_422(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());

        $res = $this->postJson("/api/productmaster/{$product->id}/bulk-prices", [
            'tiers' => [
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
                ['min_qty' => 10, 'max_qty' => 20, 'unit_price' => 5.5],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('overlaps', (string) $res->json('message'));

        // Nothing written.
        $this->assertSame(0, ProductBulkPrice::query()->where('Products_Id', $product->id)->count());
    }

    public function test_set_bulk_prices_rejects_price_below_floor_with_422(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), 5.0);

        $res = $this->postJson("/api/productmaster/{$product->id}/bulk-prices", [
            'tiers' => [
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 4.5],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('minimum selling price', (string) $res->json('message'));
        $this->assertSame(0, ProductBulkPrice::query()->where('Products_Id', $product->id)->count());
    }

    public function test_set_bulk_prices_with_empty_tiers_clears_all(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());
        $this->makeMasterTier($product, 5, 10, 6.0);
        $this->makeMasterTier($product, 20, null, 5.0);

        $res = $this->postJson("/api/productmaster/{$product->id}/bulk-prices", [
            'tiers' => [],
        ]);

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
        $this->assertSame(0, ProductBulkPrice::query()->where('Products_Id', $product->id)->count());
    }

    // ---------------------------------------------------------------
    // (b) Product detail exposes ordered tiers
    // ---------------------------------------------------------------

    public function test_show_returns_tiers_ordered_by_min_qty(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());
        $this->makeMasterTier($product, 20, 50, 5.5);
        $this->makeMasterTier($product, 5, 10, 6.0);

        $res = $this->getJson("/api/productmaster/{$product->id}");

        $res->assertOk();

        $tiers = collect($res->json('bulk_prices'));
        $this->assertCount(2, $tiers);
        $this->assertSame([5, 20], $tiers->pluck('Min_Qty')->map(fn ($v) => (int) $v)->all());
        $this->assertEquals(6.0, (float) $tiers->first()['Unit_Price']);
    }

    // ---------------------------------------------------------------
    // (c) Temp-product approval copies tiers
    // ---------------------------------------------------------------

    public function test_approve_copies_temp_tiers_to_master(): void
    {
        $this->actingAsAdmin();

        $temp = $this->makeTempProduct($this->makeVendor(), $this->ref());
        $this->makeTempTier($temp, 5, 10, 6.0);
        $this->makeTempTier($temp, 20, null, 5.0);

        $res = $this->postJson("/api/admin/products-temp/{$temp->id}/approve", [
            'commission_type'  => 'percent',
            'commission_value' => 10,
        ]);

        $res->assertOk();
        $masterId = (int) $res->json('approved_product_id');
        $this->assertGreaterThan(0, $masterId);

        $tiers = ProductBulkPrice::query()
            ->where('Products_Id', $masterId)
            ->orderBy('Min_Qty')
            ->get();

        $this->assertCount(2, $tiers);
        $this->assertSame(5, (int) $tiers[0]->Min_Qty);
        $this->assertSame(10, (int) $tiers[0]->Max_Qty);
        $this->assertEquals(6.0, (float) $tiers[0]->Unit_Price);
        $this->assertSame(20, (int) $tiers[1]->Min_Qty);
        $this->assertNull($tiers[1]->Max_Qty);
        $this->assertEquals(5.0, (float) $tiers[1]->Unit_Price);
    }

    public function test_approve_skips_invalid_temp_tier_set_without_failing_approval(): void
    {
        $this->actingAsAdmin();

        $temp = $this->makeTempProduct($this->makeVendor(), $this->ref());

        // Overlapping set: defensively skipped (with a log), approval succeeds.
        $this->makeTempTier($temp, 5, 10, 6.0);
        $this->makeTempTier($temp, 5, 10, 5.5);

        $res = $this->postJson("/api/admin/products-temp/{$temp->id}/approve", [
            'commission_type'  => 'percent',
            'commission_value' => 10,
        ]);

        $res->assertOk();
        $masterId = (int) $res->json('approved_product_id');
        $this->assertGreaterThan(0, $masterId);

        $this->assertSame(0, ProductBulkPrice::query()->where('Products_Id', $masterId)->count());
    }

    // ---------------------------------------------------------------
    // (d) Vendor update requests carrying 'bulk_prices'
    // ---------------------------------------------------------------

    public function test_update_request_with_bulk_prices_replaces_the_master_set(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), null, $this->makeVendor()->id);
        $this->makeMasterTier($product, 2, 3, 9.0);

        $request = $this->makeUpdateRequest($product, [
            'Product_Price' => 12,
            'bulk_prices'   => [
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
                ['min_qty' => 11, 'max_qty' => null, 'unit_price' => 5.0],
            ],
        ]);

        $res = $this->postJson("/api/admin/product-update-requests/{$request->id}/approve", []);

        $res->assertOk();

        $this->assertEquals(12.0, (float) $product->fresh()->Product_Price);
        $this->assertSame('approved', $request->fresh()->Status);

        $tiers = ProductBulkPrice::query()
            ->where('Products_Id', $product->id)
            ->orderBy('Min_Qty')
            ->get();

        $this->assertCount(2, $tiers);
        $this->assertSame([5, 11], $tiers->pluck('Min_Qty')->map(fn ($v) => (int) $v)->all());
        $this->assertFalse($tiers->contains(fn ($r) => (int) $r->Min_Qty === 2));
    }

    public function test_update_request_with_overlapping_bulk_prices_fails_into_failed_list(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), null, $this->makeVendor()->id);
        $this->makeMasterTier($product, 2, 3, 9.0);

        $request = $this->makeUpdateRequest($product, [
            'bulk_prices' => [
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
                ['min_qty' => 8, 'max_qty' => 20, 'unit_price' => 5.5],
            ],
        ]);

        $res = $this->postJson('/api/admin/product-update-requests/bulk/approve', [
            'ids' => [$request->id],
        ]);

        $res->assertOk();
        $this->assertSame([], $res->json('approved_ids'));

        $failed = collect($res->json('failed'));
        $failure = $failed->firstWhere('id', $request->id);
        $this->assertNotNull($failure);
        $this->assertStringContainsString('overlaps', $failure['error']);

        // Request stays open; the existing tier set is untouched (rollback).
        $this->assertSame('pending', $request->fresh()->Status);

        $tiers = ProductBulkPrice::query()->where('Products_Id', $product->id)->get();
        $this->assertCount(1, $tiers);
        $this->assertSame(2, (int) $tiers->first()->Min_Qty);
    }

    public function test_update_request_with_overlapping_bulk_prices_single_approve_returns_422(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), null, $this->makeVendor()->id);

        $request = $this->makeUpdateRequest($product, [
            'bulk_prices' => [
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 5.5],
            ],
        ]);

        $res = $this->postJson("/api/admin/product-update-requests/{$request->id}/approve", []);

        $res->assertStatus(422);
        $this->assertStringContainsString('overlaps', (string) $res->json('message'));
        $this->assertSame('pending', $request->fresh()->Status);
    }

    public function test_update_request_bulk_prices_below_floor_fails_with_422(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), 5.0, $this->makeVendor()->id);

        $request = $this->makeUpdateRequest($product, [
            'bulk_prices' => [
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 4.0],
            ],
        ]);

        $res = $this->postJson("/api/admin/product-update-requests/{$request->id}/approve", []);

        $res->assertStatus(422);
        $this->assertStringContainsString('minimum selling price', (string) $res->json('message'));
        $this->assertSame(0, ProductBulkPrice::query()->where('Products_Id', $product->id)->count());
    }

    public function test_update_request_without_bulk_prices_key_leaves_tiers_untouched(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), null, $this->makeVendor()->id);
        $this->makeMasterTier($product, 5, 10, 6.0);

        $request = $this->makeUpdateRequest($product, [
            'Product_Price' => 15,
        ]);

        $res = $this->postJson("/api/admin/product-update-requests/{$request->id}/approve", []);

        $res->assertOk();
        $this->assertEquals(15.0, (float) $product->fresh()->Product_Price);

        $tiers = ProductBulkPrice::query()->where('Products_Id', $product->id)->get();
        $this->assertCount(1, $tiers);
        $this->assertSame(5, (int) $tiers->first()->Min_Qty);
        $this->assertEquals(6.0, (float) $tiers->first()->Unit_Price);
    }

    public function test_update_request_list_and_show_expose_bulk_prices_display(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), null, $this->makeVendor()->id);
        $this->makeMasterTier($product, 2, 3, 9.0);

        $request = $this->makeUpdateRequest($product, [
            'bulk_prices' => [
                ['min_qty' => 20, 'max_qty' => null, 'unit_price' => 5.0],
                ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
            ],
        ]);

        // LIST
        $list = $this->getJson('/api/admin/product-update-requests?status=open&search=' . $product->Product_Code);
        $list->assertOk();

        $row = collect($list->json('data'))->firstWhere('id', $request->id);
        $this->assertNotNull($row);

        $display = $row['Requested_Bulk_Prices_Display'] ?? null;
        $this->assertIsArray($display);
        $this->assertCount(1, $display['current']);
        $this->assertSame('2-3', $display['current'][0]['label']);

        // Requested tiers sorted by min_qty, open-ended labelled "20+".
        $this->assertSame([5, 20], array_column($display['requested'], 'min_qty'));
        $this->assertSame('20+', $display['requested'][1]['label']);

        // SHOW
        $show = $this->getJson("/api/admin/product-update-requests/{$request->id}");
        $show->assertOk();

        $showDisplay = $show->json('data.Requested_Bulk_Prices_Display');
        $this->assertIsArray($showDisplay);
        $this->assertSame([5, 20], array_column($showDisplay['requested'], 'min_qty'));
        $this->assertCount(1, $showDisplay['current']);
    }

    public function test_update_request_with_empty_bulk_prices_array_clears_tiers(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), null, $this->makeVendor()->id);
        $this->makeMasterTier($product, 5, 10, 6.0);

        $request = $this->makeUpdateRequest($product, [
            'bulk_prices' => [],
        ]);

        $res = $this->postJson("/api/admin/product-update-requests/{$request->id}/approve", []);

        $res->assertOk();
        $this->assertSame('approved', $request->fresh()->Status);
        $this->assertSame(0, ProductBulkPrice::query()->where('Products_Id', $product->id)->count());
    }
}
