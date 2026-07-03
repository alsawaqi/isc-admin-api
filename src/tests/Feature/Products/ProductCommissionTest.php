<?php

namespace Tests\Feature\Products;

use App\Models\ProductMaster;
use App\Models\ProductTemporary;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

class ProductCommissionTest extends FeatureTestCase
{
    /**
     * Borrow valid category ids from an existing master product so the
     * NOT NULL department columns are satisfied when approving into master.
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

    private function makeVendor(): VendorMaster
    {
        return VendorMaster::create([
            'Vendor_Code'     => 'PCOMTEST_' . uniqid(),
            'Vendor_Name'     => 'Product Commission Test Vendor',
            'Approval_Status' => 'approved',
            'Status'          => 'active',
            'Is_Active'       => 1,
        ]);
    }

    private function makeTempProduct(VendorMaster $vendor, object $ref): ProductTemporary
    {
        return ProductTemporary::create([
            'Vendor_Id'         => $vendor->id,
            'Temp_Product_Code' => 'TMPTEST_' . uniqid(),

            'Product_Name'    => 'Commission Test Product ' . uniqid(),
            'Product_Name_Ar' => 'منتج اختبار العمولة',
            'Description'     => 'Commission test product description.',

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Price' => 10,
            'Product_Stock' => 5,

            'Submission_Status' => 'pending',
            'Submitted_At'      => now(),
        ]);
    }

    private function makeMasterProduct(?int $vendorId, object $ref): ProductMaster
    {
        return ProductMaster::create([
            'Product_Code' => 'PRODTEST_' . uniqid(),

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Name'        => 'Commission Master Product ' . uniqid(),
            'Product_Name_Ar'     => 'منتج رئيسي للعمولة',
            'Product_Description' => 'Commission master product description.',

            'Product_Price' => 10,
            'Product_Stock' => 5,
            'Status'        => 'available',

            'Vendor_Id'  => $vendorId,
            'Created_By' => 1,
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

    // ---------------------------------------------------------------
    // Temp-product approval now requires the commission
    // ---------------------------------------------------------------

    public function test_approve_without_commission_body_returns_422(): void
    {
        $this->actingAsAdmin();

        $temp = $this->makeTempProduct($this->makeVendor(), $this->ref());

        $res = $this->postJson("/api/admin/products-temp/{$temp->id}/approve", []);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['commission_type', 'commission_value']);

        $temp->refresh();
        $this->assertSame('pending', $temp->Submission_Status);
    }

    public function test_approve_rejects_percent_over_100(): void
    {
        $this->actingAsAdmin();

        $temp = $this->makeTempProduct($this->makeVendor(), $this->ref());

        $res = $this->postJson("/api/admin/products-temp/{$temp->id}/approve", [
            'commission_type'  => 'percent',
            'commission_value' => 150,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['commission_value']);
    }

    public function test_approve_with_commission_writes_it_to_master_and_temp(): void
    {
        $this->actingAsAdmin();

        $temp = $this->makeTempProduct($this->makeVendor(), $this->ref());

        $res = $this->postJson("/api/admin/products-temp/{$temp->id}/approve", [
            'commission_type'  => 'percent',
            'commission_value' => 10,
        ]);

        $res->assertOk();
        $masterId = (int) $res->json('approved_product_id');
        $this->assertGreaterThan(0, $masterId);

        $master = ProductMaster::query()->findOrFail($masterId);
        $this->assertSame('percent', $master->Commission_Type);
        $this->assertEquals(10.0, (float) $master->Commission_Value);

        // Temp row keeps the commission as audit (row is soft-deleted after approval)
        $tempFresh = ProductTemporary::withTrashed()->findOrFail($temp->id);
        $this->assertSame('percent', $tempFresh->Commission_Type);
        $this->assertEquals(10.0, (float) $tempFresh->Commission_Value);
        $this->assertSame('approved', $tempFresh->Submission_Status);
    }

    public function test_bulk_approve_applies_one_commission_to_all_selected(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $vendor = $this->makeVendor();
        $tempA = $this->makeTempProduct($vendor, $ref);
        $tempB = $this->makeTempProduct($vendor, $ref);

        $res = $this->postJson('/api/admin/products-temp/bulk/approve', [
            'ids'              => [$tempA->id, $tempB->id],
            'commission_type'  => 'fixed',
            'commission_value' => 2.5,
        ]);

        $res->assertOk();
        $this->assertEqualsCanonicalizing([$tempA->id, $tempB->id], $res->json('approved_ids'));
        $this->assertSame([], $res->json('failed'));

        foreach ([$tempA, $tempB] as $temp) {
            $tempFresh = ProductTemporary::withTrashed()->findOrFail($temp->id);
            $master = ProductMaster::query()->findOrFail($tempFresh->Approved_Product_Id);
            $this->assertSame('fixed', $master->Commission_Type);
            $this->assertEquals(2.5, (float) $master->Commission_Value);
        }
    }

    public function test_bulk_approve_without_commission_returns_422(): void
    {
        $this->actingAsAdmin();

        $temp = $this->makeTempProduct($this->makeVendor(), $this->ref());

        $res = $this->postJson('/api/admin/products-temp/bulk/approve', [
            'ids' => [$temp->id],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['commission_type', 'commission_value']);
    }

    // ---------------------------------------------------------------
    // Vendor master-product commission endpoint
    // ---------------------------------------------------------------

    public function test_vendor_product_commission_happy_path(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->makeVendor()->id, $this->ref());

        $res = $this->postJson("/api/admin/vendor-products/{$product->id}/commission", [
            'commission_type'  => 'fixed',
            'commission_value' => 2.5,
        ]);

        $res->assertOk();
        $res->assertJsonPath('success', true);

        $product->refresh();
        $this->assertSame('fixed', $product->Commission_Type);
        $this->assertEquals(2.5, (float) $product->Commission_Value);
    }

    public function test_vendor_product_commission_rejects_percent_over_100(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->makeVendor()->id, $this->ref());

        $res = $this->postJson("/api/admin/vendor-products/{$product->id}/commission", [
            'commission_type'  => 'percent',
            'commission_value' => 101,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['commission_value']);
    }

    public function test_vendor_product_commission_rejects_non_vendor_product(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct(null, $this->ref());

        $res = $this->postJson("/api/admin/vendor-products/{$product->id}/commission", [
            'commission_type'  => 'percent',
            'commission_value' => 10,
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('message', 'Not a vendor product.');
    }

    public function test_vendor_product_commission_rejects_zero_value(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->makeVendor()->id, $this->ref());

        $res = $this->postJson("/api/admin/vendor-products/{$product->id}/commission", [
            'commission_type'  => 'fixed',
            'commission_value' => 0,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['commission_value']);
    }
}
