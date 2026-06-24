<?php

namespace Tests\Feature\Vendor;

use App\Models\OrdersPlacedVendors;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

class VendorPayoutNotificationTest extends FeatureTestCase
{
    public function test_marking_payout_paid_sets_status_and_notifies_the_vendor(): void
    {
        $this->actingAsAdmin();

        $vendor = VendorMaster::create([
            'Vendor_Code'     => 'PAYTEST_' . uniqid(),
            'Vendor_Name'     => 'Payout Test Vendor',
            'Approval_Status' => 'approved',
            'Status'          => 'active',
            'Is_Active'       => 1,
        ]);

        // Use a real parent order id if the table has one (FK-safe), else fall back.
        $orderId = (int) (DB::table('Orders_Placed_T')->orderByDesc('id')->value('id') ?? 1);

        $vendorOrder = OrdersPlacedVendors::create([
            'Orders_Placed_Id'  => $orderId,
            'Vendor_Id'         => $vendor->id,
            'Vendor_Order_Code' => 'VORDTEST_' . uniqid(),
            'Sub_Total'         => 100,
            'VAT'               => 0,
            'Shipping'          => 0,
            'Total'             => 100,
            'Status'            => 'commission_set',
            'Commission_Amount' => 10,
            'Payout_Status'     => 'unpaid',
            // Net columns drive the payout calc (VendorPayoutRules prefers Net_Sub_Total).
            'Net_Sub_Total'              => 100,
            'Adjusted_Commission_Amount' => 10,
            'Net_Payout_Amount'          => 90,
        ]);

        $res = $this->postJson("/api/admin/vendor-orders/{$vendorOrder->id}/payout", [
            'reference' => 'REF-TEST',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.payout_status', 'paid');

        $vendorOrder->refresh();
        $this->assertSame('paid', $vendorOrder->Payout_Status);

        // A vendor-scoped notification row should have been created.
        $this->assertDatabaseHas('Conx_Notifications_T', [
            'notifiable_type' => 'App\\Models\\Vendor',
            'notifiable_id'   => $vendor->id,
        ]);
    }
}
