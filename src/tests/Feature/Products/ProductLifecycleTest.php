<?php

namespace Tests\Feature\Products;

use App\Models\ProductImages;
use App\Models\ProductMaster;
use App\Models\ProductsBarcodes;
use App\Models\ProductVendorRequest;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * Product cost / minimum-selling floor / deactivate / soft delete:
 * - store validation (min required, price >= min)
 * - update floor on the RESULTING price/min pair
 * - activate / deactivate endpoints
 * - destroy is a SOFT delete (images/barcodes/uploads untouched) + restore
 * - trashed listing filter
 * - vendor update-request price-below-floor rejection (single + bulk)
 */
class ProductLifecycleTest extends FeatureTestCase
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
            'Vendor_Code'     => 'PLIFETEST_' . uniqid(),
            'Vendor_Name'     => 'Product Lifecycle Test Vendor',
            'Approval_Status' => 'approved',
            'Status'          => 'active',
            'Is_Active'       => 1,
        ]);
    }

    private function makeMasterProduct(object $ref, array $overrides = []): ProductMaster
    {
        return ProductMaster::create(array_merge([
            'Product_Code' => 'PRODLIFE_' . uniqid(),

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Name'        => 'Lifecycle Product ' . uniqid(),
            'Product_Name_Ar'     => 'منتج دورة الحياة',
            'Product_Description' => 'Lifecycle test product description.',

            'Product_Price' => 10,
            'Product_Stock' => 5,
            'Status'        => 'available',

            'Created_By' => 1,
        ], $overrides));
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

    private function storePayload(object $ref, array $overrides = []): array
    {
        return array_merge([
            'product_department_id'         => $ref->Product_Department_Id,
            'product_sub_department_id'     => $ref->Product_Sub_Department_Id,
            'product_sub_sub_department_id' => $ref->Product_Sub_Sub_Department_Id,

            'name'        => 'Store Floor Product ' . uniqid(),
            'name_ar'     => 'منتج أرضية السعر',
            'description' => 'Store price-floor product description.',

            'minimum_selling_price' => 5,
            'price'                 => 8,
            'cost'                  => 3.25,
            'stock'                 => 4,
            'volume_type'           => 'cm',
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // (1a) Admin CREATE validation
    // ---------------------------------------------------------------

    public function test_store_without_minimum_selling_price_returns_422(): void
    {
        $this->actingAsAdmin();

        $payload = $this->storePayload($this->ref());
        unset($payload['minimum_selling_price']);

        $res = $this->postJson('/api/productmaster', $payload);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['minimum_selling_price']);
    }

    public function test_store_with_price_below_minimum_returns_422_with_floor_message(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/productmaster', $this->storePayload($this->ref(), [
            'minimum_selling_price' => 5,
            'price'                 => 4.999,
        ]));

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['price']);
        $this->assertStringContainsString(
            'Price cannot be below the minimum selling price (5.000).',
            (string) collect($res->json('errors.price'))->first()
        );
    }

    public function test_store_happy_path_persists_cost_floor_and_defaults_active(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/productmaster', $this->storePayload($this->ref()));

        $res->assertStatus(201);
        $productId = (int) $res->json('data.id');
        $this->assertGreaterThan(0, $productId);

        $product = ProductMaster::query()->findOrFail($productId);
        $this->assertEquals(5.0, (float) $product->Minimum_Selling_Price);
        $this->assertEquals(8.0, (float) $product->Product_Price);
        $this->assertEquals(3.25, (float) $product->Product_Cost);
        $this->assertEquals(1, (int) $product->Is_Active);
        $this->assertNull($product->deleted_at);
    }

    // ---------------------------------------------------------------
    // (1b) Admin UPDATE floor on the resulting pair
    // ---------------------------------------------------------------

    public function test_update_price_below_existing_floor_returns_422(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), [
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 5,
        ]);

        $res = $this->putJson("/api/productmaster/{$product->id}", ['price' => 3]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['price']);
        $this->assertStringContainsString(
            'Price cannot be below the minimum selling price (5.000).',
            (string) collect($res->json('errors.price'))->first()
        );

        $this->assertEquals(10.0, (float) $product->fresh()->Product_Price);
    }

    public function test_update_raising_floor_above_current_price_returns_422(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), [
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 5,
        ]);

        // No price in the payload: the check falls back to the DB price (10).
        $res = $this->putJson("/api/productmaster/{$product->id}", [
            'minimum_selling_price' => 20,
        ]);

        $res->assertStatus(422);
        $this->assertEquals(5.0, (float) $product->fresh()->Minimum_Selling_Price);
    }

    public function test_update_price_at_or_above_floor_succeeds(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), [
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 5,
        ]);

        $res = $this->putJson("/api/productmaster/{$product->id}", [
            'price' => 5,
            'cost'  => 2.5,
        ]);

        $res->assertOk();

        $fresh = $product->fresh();
        $this->assertEquals(5.0, (float) $fresh->Product_Price);
        $this->assertEquals(2.5, (float) $fresh->Product_Cost);
    }

    // ---------------------------------------------------------------
    // (2) Activate / deactivate
    // ---------------------------------------------------------------

    public function test_deactivate_and_activate_toggle_is_active(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());

        $res = $this->postJson("/api/productmaster/{$product->id}/deactivate");
        $res->assertOk();
        $res->assertJsonPath('success', true);
        $this->assertEquals(0, (int) $product->fresh()->Is_Active);

        $res = $this->postJson("/api/productmaster/{$product->id}/activate");
        $res->assertOk();
        $res->assertJsonPath('success', true);
        $this->assertEquals(1, (int) $product->fresh()->Is_Active);
    }

    // ---------------------------------------------------------------
    // (3) Soft delete + restore + trashed listing
    // ---------------------------------------------------------------

    public function test_destroy_soft_deletes_only_the_product_row(): void
    {
        $this->actingAsAdmin();

        Storage::fake('uploads');
        Storage::disk('uploads')->put('Products/lifecycle-test.jpg', 'fake-image-bytes');

        $product = $this->makeMasterProduct($this->ref());

        $image = ProductImages::create([
            'Product_Image_Code' => 'PIMGTEST_' . uniqid(),
            'Products_Id'        => $product->id,
            'Image_Path'         => 'Products/lifecycle-test.jpg',
            'Image_Size'         => 16,
            'Image_Extension'    => 'jpg',
            'Image_Type'         => 'image/jpeg',
            'Created_By'         => 1,
            'Created_Date'       => now(),
        ]);

        $barcode = ProductsBarcodes::create([
            'Product_Barcode_Code' => 'PRBARTEST_' . uniqid(),
            'Products_Id'          => $product->id,
            'Supplier_Barcode'     => 'BAR-' . uniqid(),
            'Created_By'           => 1,
            'Created_Date'         => now(),
        ]);

        $res = $this->deleteJson("/api/productmaster/{$product->id}");
        $res->assertOk();

        // Product row is soft-deleted (excluded by default, present withTrashed).
        $this->assertNull(ProductMaster::find($product->id));
        $trashed = ProductMaster::withTrashed()->findOrFail($product->id);
        $this->assertTrue($trashed->trashed());
        $this->assertNotNull($trashed->deleted_at);

        // Images and barcodes are NOT deleted, and the uploaded file is NOT touched.
        $this->assertNotNull(ProductImages::find($image->id));
        $this->assertNotNull(ProductsBarcodes::find($barcode->id));
        Storage::disk('uploads')->assertExists('Products/lifecycle-test.jpg');
    }

    public function test_restore_brings_a_soft_deleted_product_back(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());

        $this->deleteJson("/api/productmaster/{$product->id}")->assertOk();
        $this->assertNull(ProductMaster::find($product->id));

        $res = $this->postJson("/api/productmaster/{$product->id}/restore");
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $fresh = ProductMaster::find($product->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_index_excludes_trashed_by_default_and_lists_them_with_trashed_filter(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref());
        $this->deleteJson("/api/productmaster/{$product->id}")->assertOk();

        // Default listing: trashed row excluded.
        $default = $this->getJson('/api/productmaster?search=' . $product->Product_Code);
        $default->assertOk();
        $this->assertNull(collect($default->json('data'))->firstWhere('id', $product->id));

        // ?trashed=1 -> onlyTrashed.
        $trashed = $this->getJson('/api/productmaster?trashed=1&search=' . $product->Product_Code);
        $trashed->assertOk();
        $row = collect($trashed->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($row, 'Trashed product missing from ?trashed=1 listing.');
        $this->assertNotNull($row['deleted_at']);

        // status=deleted behaves the same (UI contract).
        $byStatus = $this->getJson('/api/productmaster?status=deleted&search=' . $product->Product_Code);
        $byStatus->assertOk();
        $this->assertNotNull(collect($byStatus->json('data'))->firstWhere('id', $product->id));
    }

    // ---------------------------------------------------------------
    // (1c) Vendor update-request price floor
    // ---------------------------------------------------------------

    public function test_single_update_request_below_floor_returns_422(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), [
            'Vendor_Id'             => $this->makeVendor()->id,
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 5,
        ]);

        $request = $this->makeUpdateRequest($product, ['Product_Price' => 3]);

        $res = $this->postJson("/api/admin/product-update-requests/{$request->id}/approve");

        $res->assertStatus(422);
        $this->assertStringContainsString(
            'Price cannot be below the minimum selling price (5.000).',
            (string) $res->json('message')
        );

        // Nothing applied, request still open.
        $this->assertEquals(10.0, (float) $product->fresh()->Product_Price);
        $this->assertSame('pending', $request->fresh()->Status);
    }

    public function test_bulk_update_request_below_floor_lands_in_failed_with_floor_message(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $vendor = $this->makeVendor();

        $productBad = $this->makeMasterProduct($ref, [
            'Vendor_Id'             => $vendor->id,
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 5,
        ]);
        $productOk = $this->makeMasterProduct($ref, [
            'Vendor_Id'             => $vendor->id,
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 5,
        ]);

        $badRequest = $this->makeUpdateRequest($productBad, ['Product_Price' => 3]);
        $okRequest = $this->makeUpdateRequest($productOk, ['Product_Price' => 7]);

        $res = $this->postJson('/api/admin/product-update-requests/bulk/approve', [
            'ids' => [$badRequest->id, $okRequest->id],
        ]);

        $res->assertOk();
        $this->assertSame([$okRequest->id], $res->json('approved_ids'));

        $failure = collect($res->json('failed'))->firstWhere('id', $badRequest->id);
        $this->assertNotNull($failure);
        $this->assertStringContainsString(
            'Price cannot be below the minimum selling price (5.000).',
            (string) $failure['error']
        );

        $this->assertEquals(10.0, (float) $productBad->fresh()->Product_Price);
        $this->assertSame('pending', $badRequest->fresh()->Status);
        $this->assertEquals(7.0, (float) $productOk->fresh()->Product_Price);
        $this->assertSame('approved', $okRequest->fresh()->Status);
    }

    public function test_update_request_at_or_above_floor_is_applied(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->ref(), [
            'Vendor_Id'             => $this->makeVendor()->id,
            'Product_Price'         => 10,
            'Minimum_Selling_Price' => 5,
        ]);

        $request = $this->makeUpdateRequest($product, ['Product_Price' => 5]);

        $res = $this->postJson("/api/admin/product-update-requests/{$request->id}/approve");

        $res->assertOk();
        $this->assertEquals(5.0, (float) $product->fresh()->Product_Price);
        $this->assertSame('approved', $request->fresh()->Status);
    }
}
