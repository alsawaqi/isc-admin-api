<?php

namespace Tests\Feature\Vendor;

use App\Models\OrdersPlacedVendors;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

class VendorOrderCommissionConfirmTest extends FeatureTestCase
{
    private function makeVendorOrder(array $overrides = []): OrdersPlacedVendors
    {
        $vendor = VendorMaster::create([
            'Vendor_Code'     => 'COMTEST_' . uniqid(),
            'Vendor_Name'     => 'Commission Test Vendor',
            'Approval_Status' => 'approved',
            'Status'          => 'active',
            'Is_Active'       => 1,
        ]);

        // Use a real parent order id if the table has one (FK-safe), else fall back.
        $orderId = (int) (DB::table('Orders_Placed_T')->orderByDesc('id')->value('id') ?? 1);

        return OrdersPlacedVendors::create(array_merge([
            'Orders_Placed_Id'  => $orderId,
            'Vendor_Id'         => $vendor->id,
            'Vendor_Order_Code' => 'VORDTEST_' . uniqid(),
            'Sub_Total'         => 100,
            'VAT'               => 0,
            'Shipping'          => 0,
            'Total'             => 100,
            // Auto-computed commission awaiting accountant confirmation
            'Status'            => 'commission_auto',
            'Commission_Type'   => 'auto',
            'Commission_Amount' => 10,
            'Commission_Source' => 'auto',
            'Payout_Status'     => 'unpaid',
        ], $overrides));
    }

    public function test_confirm_commission_happy_path(): void
    {
        $admin = $this->actingAsAdmin();

        $vendorOrder = $this->makeVendorOrder();

        $res = $this->postJson("/api/admin/vendor-orders/{$vendorOrder->id}/confirm-commission");

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.Status', 'commission_set');

        $vendorOrder->refresh();
        $this->assertSame('commission_set', $vendorOrder->Status);
        $this->assertSame((int) $admin->id, (int) $vendorOrder->Commission_Confirmed_By);
        $this->assertNotNull($vendorOrder->Commission_Confirmed_At);
    }

    public function test_confirm_commission_rejects_pending_status(): void
    {
        $this->actingAsAdmin();

        $vendorOrder = $this->makeVendorOrder([
            'Status'            => 'pending',
            'Commission_Type'   => null,
            'Commission_Amount' => null,
            'Commission_Source' => null,
        ]);

        $res = $this->postJson("/api/admin/vendor-orders/{$vendorOrder->id}/confirm-commission");

        $res->assertStatus(422);
        $res->assertJsonPath('success', false);

        $vendorOrder->refresh();
        $this->assertSame('pending', $vendorOrder->Status);
    }

    public function test_confirm_commission_rejects_already_paid(): void
    {
        $this->actingAsAdmin();

        $vendorOrder = $this->makeVendorOrder([
            'Payout_Status' => 'paid',
        ]);

        $res = $this->postJson("/api/admin/vendor-orders/{$vendorOrder->id}/confirm-commission");

        $res->assertStatus(422);
        $res->assertJsonPath('success', false);
    }

    public function test_bulk_confirm_commission_mixed_eligible_and_ineligible(): void
    {
        $this->actingAsAdmin();

        $eligible = $this->makeVendorOrder();
        $pending = $this->makeVendorOrder([
            'Status'            => 'pending',
            'Commission_Type'   => null,
            'Commission_Amount' => null,
            'Commission_Source' => null,
        ]);

        $missingId = (int) (OrdersPlacedVendors::query()->max('id') ?? 0) + 999999;

        $res = $this->postJson('/api/admin/vendor-orders/bulk/confirm-commission', [
            'ids' => [$eligible->id, $pending->id, $missingId],
        ]);

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.confirmed_count', 1);
        $res->assertJsonCount(2, 'data.skipped');

        $skippedIds = collect($res->json('data.skipped'))->pluck('id')->all();
        $this->assertContains($pending->id, $skippedIds);
        $this->assertContains($missingId, $skippedIds);

        $eligible->refresh();
        $this->assertSame('commission_set', $eligible->Status);

        $pending->refresh();
        $this->assertSame('pending', $pending->Status);
    }

    public function test_bulk_confirm_commission_requires_ids(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson('/api/admin/vendor-orders/bulk/confirm-commission', [
            'ids' => [],
        ]);

        $res->assertStatus(422);
    }

    public function test_mark_payout_paid_rejects_unconfirmed_commission_auto(): void
    {
        $this->actingAsAdmin();

        $vendorOrder = $this->makeVendorOrder([
            'Net_Sub_Total'              => 100,
            'Adjusted_Commission_Amount' => 10,
            'Net_Payout_Amount'          => 90,
        ]);

        $res = $this->postJson("/api/admin/vendor-orders/{$vendorOrder->id}/payout", [
            'reference' => 'REF-TEST',
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('message', 'Commission must be confirmed before payout.');

        $vendorOrder->refresh();
        $this->assertNotSame('paid', $vendorOrder->Payout_Status);
    }

    public function test_set_commission_rejects_already_paid_order(): void
    {
        $this->actingAsAdmin();

        $vendorOrder = $this->makeVendorOrder([
            'Status'        => 'commission_set',
            'Payout_Status' => 'paid',
        ]);

        $res = $this->postJson("/api/admin/vendor-orders/{$vendorOrder->id}/commission", [
            'commission_type'  => 'percent',
            'commission_value' => 5,
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('success', false);
    }

    public function test_set_commission_marks_manual_source_and_confirmation(): void
    {
        $admin = $this->actingAsAdmin();

        $vendorOrder = $this->makeVendorOrder();

        $res = $this->postJson("/api/admin/vendor-orders/{$vendorOrder->id}/commission", [
            'commission_type'  => 'percent',
            'commission_value' => 5,
        ]);

        $res->assertOk();
        $res->assertJsonPath('success', true);

        $vendorOrder->refresh();
        $this->assertSame('commission_set', $vendorOrder->Status);
        $this->assertSame('manual', $vendorOrder->Commission_Source);
        $this->assertSame((int) $admin->id, (int) $vendorOrder->Commission_Confirmed_By);
        $this->assertNotNull($vendorOrder->Commission_Confirmed_At);
    }
}
