<?php

namespace Tests\Feature\Orders;

use App\Models\OrdersPlaced;
use App\Models\OrdersPlacedVendors;
use App\Models\ProductMaster;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

class OrdersPlacedListingTest extends FeatureTestCase
{
    /**
     * Endpoints that return a Laravel paginator at the top level
     * (i.e. they have a `data` array plus pagination meta).
     *
     * @return array<string, array{0:string}>
     */
    public static function paginatedEndpoints(): array
    {
        return [
            'index (pending)'      => ['/api/orders-placed'],
            'packing'              => ['/api/orders-placed/packing'],
            'dispatch'             => ['/api/orders-placed/dispatch'],
            'shipment'             => ['/api/orders-placed/shipment'],
            'pickup'               => ['/api/orders-placed/pickup'],
            'delivered'            => ['/api/orders-placed/delivered'],
        ];
    }

    #[DataProvider('paginatedEndpoints')]
    public function test_listing_endpoints_return_paginated_shape(string $url): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson($url . '?per_page=5');

        $res->assertOk();
        // Laravel paginator top-level keys
        $res->assertJsonStructure([
            'data',
            'current_page',
            'per_page',
            'total',
        ]);
    }

    public function test_customer_listing_returns_data_and_customer_keys(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/orders-placed/customer?per_page=5');

        $res->assertOk();
        // index_customer wraps the paginator under `data` and adds `customer`
        $res->assertJsonStructure([
            'data' => [
                'data',
                'current_page',
                'per_page',
                'total',
            ],
        ]);
        $this->assertArrayHasKey('customer', $res->json());
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/orders-placed')->assertUnauthorized();
    }

    public function test_delivered_requires_authentication(): void
    {
        $this->getJson('/api/orders-placed/delivered')->assertUnauthorized();
    }

    public function test_view_all_orders_hides_provisional_card_drafts(): void
    {
        $this->actingAsAdmin();
        $marker = 'ACTUAL_'.strtoupper(substr(uniqid(), -8));

        $draft = $this->makeOrder([
            'Order_Code' => $marker.'_D',
            'Transaction_Number' => $marker.'_D',
        ]);
        DB::table('Orders_Placed_T')->where('id', $draft->id)->update([
            'Payment_Method' => 'card',
            'Payment_Status' => 'pending',
        ]);

        $paid = $this->makeOrder([
            'Order_Code' => $marker.'_P',
            'Transaction_Number' => $marker.'_P',
        ]);
        DB::table('Orders_Placed_T')->where('id', $paid->id)->update([
            'Payment_Method' => 'card',
            'Payment_Status' => 'paid',
        ]);

        $response = $this->getJson('/api/orders-placed/delivered?status=all&search='.$marker.'&per_page=10');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($ids->contains((int) $paid->id));
        $this->assertFalse($ids->contains((int) $draft->id));
    }

    public function test_show_returns_404_for_missing_order(): void
    {
        $this->actingAsAdmin();

        // findOrFail on a non-existent id must yield a 404.
        $this->getJson('/api/orders-placed/999999999')->assertNotFound();
    }

    public function test_overview_returns_404_for_missing_order(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/orders-placed/999999999/overview')->assertNotFound();
    }

    public function test_complete_transition_returns_404_for_missing_order(): void
    {
        $this->actingAsAdmin();

        // complete() does findOrFail($id) before any work, so a bad id => 404.
        $this->postJson('/api/orders-placed/complete/999999999')->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Item ownership: vendor public info on details, has_vendor_items on lists
    // ---------------------------------------------------------------

    /**
     * Borrow valid category ids from an existing master product so the
     * NOT NULL department columns are satisfied (live-schema testing).
     */
    private function refCategoryIds(): object
    {
        $ref = DB::table('Products_Master_T')
            ->whereNotNull('Product_Sub_Sub_Department_Id')
            ->orderByDesc('id')
            ->first([
                'Product_Department_Id',
                'Product_Sub_Department_Id',
                'Product_Sub_Sub_Department_Id',
            ]);

        if (! $ref) {
            $this->markTestSkipped('No master product with category ids available to borrow from.');
        }

        return $ref;
    }

    private function makeVendor(): VendorMaster
    {
        return VendorMaster::create([
            'Vendor_Code'          => 'ORDOWN_' . uniqid(),
            'Vendor_Name'          => 'Order Ownership Vendor',
            'Trade_Name'           => 'Order Ownership Trading',
            'Email_1'              => 'ordown_' . uniqid() . '@example.com',
            'Phone_No'             => '99990001',
            'Contact_Person_Name'  => 'Owner Contact',
            'Contact_Person_Title' => 'Manager',
            'Contact_Person_Email' => 'ordown_cp_' . uniqid() . '@example.com',
            'Contact_Person_Phone' => '99990002',
            // Sensitive columns that must NEVER leak into order responses.
            'Bank_Name'            => 'Secret Test Bank',
            'Bank_Account_Name'    => 'Secret Account Name',
            'Bank_Account_Number'  => '000111222333',
            'Bank_IBAN'            => 'OM00SECRETIBAN0000000001',
            'Bank_Swift_Code'      => 'SECRETXX',
            'Approval_Status'      => 'approved',
            'Status'               => 'active',
            'Is_Active'            => 1,
        ]);
    }

    private function makeProduct(?int $vendorId = null): ProductMaster
    {
        $ref = $this->refCategoryIds();

        return ProductMaster::create([
            'Product_Code' => 'ORDOWN_' . uniqid(),

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Name'        => 'Ownership Product ' . uniqid(),
            'Product_Name_Ar'     => 'منتج ملكية الطلب',
            'Product_Description' => 'Order ownership test product.',

            'Product_Price' => 10,
            'Product_Stock' => 5,
            'Status'        => 'available',
            'Vendor_Id'     => $vendorId,

            'Created_By' => 1,
        ]);
    }

    private function makeOrder(array $overrides = []): OrdersPlaced
    {
        $customerId = DB::table('Customers_Master_T')->value('id');

        if (! $customerId) {
            $this->markTestSkipped('No customer row available to attach the order to.');
        }

        return OrdersPlaced::create(array_merge([
            'Order_Code'         => 'ORDOWN_' . uniqid(),
            'Transaction_Number' => 'TNOWN_' . uniqid(),
            'Customers_Id'       => $customerId,
            'Status'             => 'pending',
            'Sub_Total_Price'    => 10,
            'Total_Price'        => 10,
        ], $overrides));
    }

    /**
     * Vendor_Id is intentionally not mass-assignable on OrdersPlacedDetails,
     * so insert order lines directly.
     */
    private function makeLine(int $orderId, int $productId, ?int $vendorId = null): int
    {
        return (int) DB::table('Orders_Placed_Details_T')->insertGetId([
            // SQL Server's unique index on Order_Placed_Code treats NULL as a
            // duplicate key, so every fixture line needs a distinct code.
            'Order_Placed_Code' => 'OPCOWN_' . uniqid('', true),
            'Orders_Placed_Id' => $orderId,
            'Products_Id'      => $productId,
            'Quantity'         => 1,
            'Price'            => 10,
            'Status'           => 'pending',
            'Vendor_Id'        => $vendorId,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function assertNoBankKeys(array $vendorPayload): void
    {
        foreach (array_keys($vendorPayload) as $key) {
            $this->assertStringStartsNotWith(
                'Bank_',
                $key,
                'Order responses must never expose vendor bank columns.'
            );
        }
    }

    public function test_show_exposes_vendor_public_info_without_bank_columns(): void
    {
        $this->actingAsAdmin();

        $vendor        = $this->makeVendor();
        $vendorProduct = $this->makeProduct($vendor->id);
        $iscProduct    = $this->makeProduct(null);

        $order = $this->makeOrder();
        $this->makeLine($order->id, $vendorProduct->id, $vendor->id);
        $this->makeLine($order->id, $iscProduct->id, null);

        $res = $this->getJson("/api/orders-placed/{$order->id}");
        $res->assertOk();

        $lines = collect($res->json('orderlist'));

        $vendorLine = $lines->firstWhere('Products_Id', $vendorProduct->id);
        $this->assertNotNull($vendorLine, 'Vendor-owned line missing from show() response.');

        $vendorPayload = $vendorLine['product']['vendor'] ?? null;
        $this->assertIsArray($vendorPayload, 'Vendor public info missing on vendor-owned line.');
        $this->assertSame($vendor->Vendor_Name, $vendorPayload['Vendor_Name']);
        $this->assertSame($vendor->Vendor_Code, $vendorPayload['Vendor_Code']);
        $this->assertSame($vendor->Contact_Person_Name, $vendorPayload['Contact_Person_Name']);
        $this->assertNoBankKeys($vendorPayload);

        // Company-owned line (Vendor_Id null) => vendor is null.
        $iscLine = $lines->firstWhere('Products_Id', $iscProduct->id);
        $this->assertNotNull($iscLine, 'Company-owned line missing from show() response.');
        $this->assertNull($iscLine['product']['vendor'] ?? null);
    }

    public function test_overview_scopes_vendor_to_public_columns(): void
    {
        $this->actingAsAdmin();

        $vendor        = $this->makeVendor();
        $vendorProduct = $this->makeProduct($vendor->id);

        $order = $this->makeOrder(['Status' => 'delivered']);
        $this->makeLine($order->id, $vendorProduct->id, $vendor->id);

        $res = $this->getJson("/api/orders-placed/{$order->id}/overview");
        $res->assertOk();

        $line = collect($res->json('details'))->firstWhere('Products_Id', $vendorProduct->id);
        $this->assertNotNull($line, 'Vendor-owned line missing from overview() details.');

        $vendorPayload = $line['product']['vendor'] ?? null;
        $this->assertIsArray($vendorPayload, 'Vendor public info missing on overview() line.');
        $this->assertSame($vendor->Vendor_Name, $vendorPayload['Vendor_Name']);
        $this->assertNoBankKeys($vendorPayload);
    }

    private function makeVendorOrder(int $orderId, int $vendorId): OrdersPlacedVendors
    {
        return OrdersPlacedVendors::create([
            'Orders_Placed_Id'  => $orderId,
            'Vendor_Id'         => $vendorId,
            'Vendor_Order_Code' => 'VOOWN_' . uniqid('', true),
            'Sub_Total'         => 10,
            'VAT'               => 0,
            'Shipping'          => 0,
            'Total'             => 10,
            'Status'            => 'pending',
        ]);
    }

    /**
     * vendorOrders.vendor is eager-loaded on show(), overview() and the
     * delivered list — all three must scope the vendor to public columns
     * (Bank_* must never leak through vendor sub-orders either).
     */
    public function test_vendor_sub_orders_expose_only_public_vendor_columns(): void
    {
        $this->actingAsAdmin();

        $vendor        = $this->makeVendor();
        $vendorProduct = $this->makeProduct($vendor->id);

        $order = $this->makeOrder(['Status' => 'delivered']);
        $this->makeLine($order->id, $vendorProduct->id, $vendor->id);
        $this->makeVendorOrder($order->id, $vendor->id);

        $assertScoped = function (?array $vendorOrders, string $endpoint) use ($vendor): void {
            $this->assertIsArray($vendorOrders, "vendor_orders missing from {$endpoint} response.");
            $row = collect($vendorOrders)->firstWhere('Vendor_Id', $vendor->id);
            $this->assertNotNull($row, "Vendor sub-order missing from {$endpoint} response.");

            $vendorPayload = $row['vendor'] ?? null;
            $this->assertIsArray($vendorPayload, "vendor relation missing on {$endpoint} vendor sub-order.");
            $this->assertSame($vendor->Vendor_Name, $vendorPayload['Vendor_Name']);
            $this->assertNoBankKeys($vendorPayload);
        };

        // show()
        $res = $this->getJson("/api/orders-placed/{$order->id}");
        $res->assertOk();
        $assertScoped($res->json('vendor_orders'), 'show()');

        // overview()
        $res = $this->getJson("/api/orders-placed/{$order->id}/overview");
        $res->assertOk();
        $assertScoped($res->json('vendor_orders'), 'overview()');

        // delivered list (delivered_index also eager-loads vendorOrders.vendor)
        $res = $this->getJson('/api/orders-placed/delivered?search=' . $order->Transaction_Number);
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $assertScoped($res->json('data.0.vendor_orders'), 'delivered list');
    }

    public function test_stage_list_reports_has_vendor_items_flag(): void
    {
        $this->actingAsAdmin();

        $vendor        = $this->makeVendor();
        $vendorProduct = $this->makeProduct($vendor->id);
        $iscProduct    = $this->makeProduct(null);

        $orderWithVendorItems = $this->makeOrder();
        $this->makeLine($orderWithVendorItems->id, $vendorProduct->id, $vendor->id);
        $this->makeLine($orderWithVendorItems->id, $iscProduct->id, null);

        $orderWithoutVendorItems = $this->makeOrder();
        $this->makeLine($orderWithoutVendorItems->id, $iscProduct->id, null);

        // index() matches Transaction_Number exactly, so each search isolates one order.
        $res = $this->getJson('/api/orders-placed?search=' . $orderWithVendorItems->Transaction_Number);
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertArrayHasKey('has_vendor_items', $res->json('data.0'));
        $this->assertTrue((bool) $res->json('data.0.has_vendor_items'));

        $res = $this->getJson('/api/orders-placed?search=' . $orderWithoutVendorItems->Transaction_Number);
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertArrayHasKey('has_vendor_items', $res->json('data.0'));
        $this->assertFalse((bool) $res->json('data.0.has_vendor_items'));
    }
}
