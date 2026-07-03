<?php

namespace Tests\Feature\Products;

use App\Models\ProductMaster;
use App\Models\ProductVendorRequest;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/**
 * Vendor product UPDATE requests: server-computed diff display in the list
 * endpoint + the bulk approve endpoint.
 */
class ProductUpdateRequestBulkApproveTest extends FeatureTestCase
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
            'Vendor_Code'     => 'PUPDTEST_' . uniqid(),
            'Vendor_Name'     => 'Product Update Request Test Vendor',
            'Approval_Status' => 'approved',
            'Status'          => 'active',
            'Is_Active'       => 1,
        ]);
    }

    private function makeMasterProduct(VendorMaster $vendor, object $ref, float $price = 1.0): ProductMaster
    {
        return ProductMaster::create([
            'Product_Code' => 'PRODUPD_' . uniqid(),

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Name'        => 'Update Request Product ' . uniqid(),
            'Product_Name_Ar'     => 'منتج طلب تحديث',
            'Product_Description' => 'Update request test product description.',

            'Product_Price' => $price,
            'Product_Stock' => 5,
            'Status'        => 'available',

            'Vendor_Id'  => $vendor->id,
            'Created_By' => 1,
        ]);
    }

    private function makeUpdateRequest(
        ProductMaster $product,
        array $changes,
        string $status = 'pending'
    ): ProductVendorRequest {
        return ProductVendorRequest::create([
            'Products_Id'            => $product->id,
            'Vendor_Id'              => $product->Vendor_Id,
            'Request_Type'           => 'approved_update',
            'Status'                 => $status,
            'Requested_Changes_Json' => $changes,
            'Action_By_Role'         => 'vendor',
            'Action_At'              => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // (a) List diff display
    // ---------------------------------------------------------------

    public function test_list_includes_requested_changes_display_with_current_and_requested(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->makeVendor(), $this->ref(), 1.0);
        $request = $this->makeUpdateRequest($product, ['Product_Price' => 5]);

        $res = $this->getJson('/api/admin/product-update-requests?status=open&search=' . $product->Product_Code);

        $res->assertOk();

        $rows = collect($res->json('data'));
        $row = $rows->firstWhere('id', $request->id);
        $this->assertNotNull($row, 'Fixtured update request missing from the list.');

        $display = collect($row['Requested_Changes_Display'] ?? []);
        $entry = $display->firstWhere('key', 'Product_Price');

        $this->assertNotNull($entry, 'Requested_Changes_Display has no Product_Price entry.');
        $this->assertSame('Price', $entry['label']);
        $this->assertEquals(1.0, (float) $entry['current']);
        $this->assertEquals(5.0, (float) $entry['requested']);

        // Images summary present with cheap counts (no image updates here).
        $this->assertSame(['added' => 0, 'removed' => 0], $row['Requested_Images_Display']);
    }

    // ---------------------------------------------------------------
    // (b) Bulk approve happy path
    // ---------------------------------------------------------------

    public function test_bulk_approve_applies_changes_and_marks_rows_approved(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $vendor = $this->makeVendor();

        $productA = $this->makeMasterProduct($vendor, $ref, 1.0);
        $productB = $this->makeMasterProduct($vendor, $ref, 2.0);

        $requestA = $this->makeUpdateRequest($productA, ['Product_Price' => 5]);
        $requestB = $this->makeUpdateRequest($productB, ['Product_Stock' => 42]);

        $res = $this->postJson('/api/admin/product-update-requests/bulk/approve', [
            'ids' => [$requestA->id, $requestB->id],
        ]);

        $res->assertOk();
        $this->assertEqualsCanonicalizing([$requestA->id, $requestB->id], $res->json('approved_ids'));
        $this->assertSame([], $res->json('failed'));

        $this->assertEquals(5.0, (float) $productA->fresh()->Product_Price);
        $this->assertEquals(42, (int) $productB->fresh()->Product_Stock);

        $this->assertSame('approved', $requestA->fresh()->Status);
        $this->assertSame('approved', $requestB->fresh()->Status);
    }

    // ---------------------------------------------------------------
    // (c) Bulk approve mixed outcomes
    // ---------------------------------------------------------------

    public function test_bulk_approve_reports_missing_and_closed_ids_as_failed(): void
    {
        $this->actingAsAdmin();

        $ref = $this->ref();
        $vendor = $this->makeVendor();

        $productOpen = $this->makeMasterProduct($vendor, $ref, 1.0);
        $openRequest = $this->makeUpdateRequest($productOpen, ['Product_Price' => 3]);

        $productClosed = $this->makeMasterProduct($vendor, $ref, 1.0);
        $approvedRequest = $this->makeUpdateRequest($productClosed, ['Product_Price' => 9], 'approved');

        $missingId = 999999999;

        $res = $this->postJson('/api/admin/product-update-requests/bulk/approve', [
            'ids' => [$openRequest->id, $missingId, $approvedRequest->id],
        ]);

        $res->assertOk();
        $this->assertSame([$openRequest->id], $res->json('approved_ids'));

        $failed = collect($res->json('failed'));
        $this->assertCount(2, $failed);

        $missingFailure = $failed->firstWhere('id', $missingId);
        $this->assertNotNull($missingFailure);
        $this->assertStringContainsString('not found', $missingFailure['error']);

        $closedFailure = $failed->firstWhere('id', $approvedRequest->id);
        $this->assertNotNull($closedFailure);
        $this->assertStringContainsString('not open', $closedFailure['error']);

        // The closed request's changes were NOT applied.
        $this->assertEquals(1.0, (float) $productClosed->fresh()->Product_Price);
        $this->assertEquals(3.0, (float) $productOpen->fresh()->Product_Price);
    }

    // ---------------------------------------------------------------
    // (d) Bulk approve validation
    // ---------------------------------------------------------------

    public function test_bulk_approve_rejects_empty_ids(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/admin/product-update-requests/bulk/approve', [
            'ids' => [],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['ids']);
    }

    public function test_bulk_approve_rejects_more_than_200_ids(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/admin/product-update-requests/bulk/approve', [
            'ids' => range(1, 201),
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['ids']);
    }

    // ---------------------------------------------------------------
    // (e) Commission fields can never leak through update requests
    // ---------------------------------------------------------------

    public function test_commission_fields_are_not_displayed_and_not_applied(): void
    {
        $this->actingAsAdmin();

        $product = $this->makeMasterProduct($this->makeVendor(), $this->ref(), 1.0);
        $request = $this->makeUpdateRequest($product, [
            'Product_Price'    => 5,
            'Commission_Type'  => 'percent',
            'Commission_Value' => 50,
        ]);

        // Not shown in the list diff display.
        $res = $this->getJson('/api/admin/product-update-requests?status=open&search=' . $product->Product_Code);
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $request->id);
        $this->assertNotNull($row);

        $displayKeys = collect($row['Requested_Changes_Display'] ?? [])->pluck('key');
        $this->assertTrue($displayKeys->contains('Product_Price'));
        $this->assertFalse($displayKeys->contains('Commission_Type'));
        $this->assertFalse($displayKeys->contains('Commission_Value'));

        // Not applied on (bulk) approval.
        $approve = $this->postJson('/api/admin/product-update-requests/bulk/approve', [
            'ids' => [$request->id],
        ]);

        $approve->assertOk();
        $this->assertSame([$request->id], $approve->json('approved_ids'));

        $fresh = $product->fresh();
        $this->assertEquals(5.0, (float) $fresh->Product_Price);
        $this->assertNull($fresh->Commission_Type);
        $this->assertNull($fresh->Commission_Value);
    }
}
