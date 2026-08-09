<?php

namespace Tests\Feature\Orders;

use App\Models\OrderProcessLog;
use App\Models\OrdersPlaced;
use App\Models\ProductMaster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * Pickup handover: pickup-complete is gated on collector name/contact/ID image
 * (persisted to Orders_Placed_T + private local file), and the ID copy is only
 * reachable through the presigned pickup-id-url endpoint.
 *
 * The ID-image test uses Storage::fake('private_uploads') (same precedent as
 * ProductLifecycleTest). temporaryUrl() throws on a fake disk, so the
 * pickup-id-url test only covers the 404 (no image) path.
 */
class OrdersPickupHandoverTest extends FeatureTestCase
{
    private function requirePickupColumns(): void
    {
        if (! Schema::hasColumn('Orders_Placed_T', 'Pickup_Person_Name')) {
            $this->markTestSkipped('Pickup person columns are not migrated on this database.');
        }
    }

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

    private function makeProduct(): ProductMaster
    {
        $ref = $this->refCategoryIds();

        return ProductMaster::create([
            'Product_Code' => 'PICKUP_' . uniqid(),

            'Product_Department_Id'         => $ref->Product_Department_Id,
            'Product_Sub_Department_Id'     => $ref->Product_Sub_Department_Id,
            'Product_Sub_Sub_Department_Id' => $ref->Product_Sub_Sub_Department_Id,

            'Product_Name'        => 'Pickup Handover Product ' . uniqid(),
            'Product_Name_Ar'     => 'منتج استلام',
            'Product_Description' => 'Pickup handover test product.',

            'Product_Price' => 10,
            'Product_Stock' => 5,
            'Status'        => 'available',

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
            'Order_Code'         => 'PICKUP_' . uniqid(),
            'Transaction_Number' => 'TNPICK_' . uniqid(),
            'Customers_Id'       => $customerId,
            'Status'             => 'ready_for_collection',
            'Delivery_Type'      => 'pickup',
            'Sub_Total_Price'    => 10,
            'Total_Price'        => 10,
        ], $overrides));
    }

    private function makeLine(int $orderId, int $productId): int
    {
        return (int) DB::table('Orders_Placed_Details_T')->insertGetId([
            // SQL Server's unique index on Order_Placed_Code treats NULL as a
            // duplicate key, so every fixture line needs a distinct code.
            'Order_Placed_Code' => 'OPCPK_' . uniqid('', true),
            'Orders_Placed_Id' => $orderId,
            'Products_Id'      => $productId,
            'Quantity'         => 1,
            'Price'            => 10,
            'Status'           => 'ready_for_collection',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // (a) Missing collector fields => 422 (gate active once columns exist)
    // ---------------------------------------------------------------

    public function test_pickup_complete_without_collector_fields_returns_422(): void
    {
        $this->requirePickupColumns();
        $this->actingAsAdmin();

        $order = $this->makeOrder();

        $res = $this->postJson("/api/orders-placed/{$order->id}/pickup-complete");

        $res->assertStatus(422);
        $res->assertJsonValidationErrors([
            'pickup_person_name',
            'pickup_person_contact',
            'id_image',
        ]);

        // Gate held: nothing was delivered.
        $this->assertSame('ready_for_collection', $order->fresh()->Status);
    }

    // ---------------------------------------------------------------
    // (b) + (d) Full handover: delivered, fields persisted, private file
    //     stored under PickupIds/{id}/, response echoes the pickup fields
    // ---------------------------------------------------------------

    public function test_pickup_complete_with_collector_fields_persists_and_stores_id_image(): void
    {
        $this->requirePickupColumns();
        $admin = $this->actingAsAdmin();

        Storage::fake('private_uploads');

        $order  = $this->makeOrder();
        $lineId = $this->makeLine($order->id, $this->makeProduct()->id);

        $res = $this->post("/api/orders-placed/{$order->id}/pickup-complete", [
            'pickup_person_name'    => 'Ahmed Al Collector',
            'pickup_person_contact' => '+968 99112233',
            // ->create() (not ->image()): the app container has no GD extension.
            'id_image'              => UploadedFile::fake()->create('national-id.jpg', 120, 'image/jpeg'),
        ], ['Accept' => 'application/json']);

        $res->assertOk();

        // (d) Response includes the pickup person fields.
        $res->assertJsonPath('status', 'delivered');
        $res->assertJsonPath('pickup_person_name', 'Ahmed Al Collector');
        $res->assertJsonPath('pickup_person_contact', '+968 99112233');
        $this->assertNotNull($res->json('picked_up_at'));
        $this->assertSame($admin->id, (int) $res->json('picked_up_by'));

        // Order + line delivered, collector fields persisted.
        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->Status);
        $this->assertSame('Ahmed Al Collector', $fresh->Pickup_Person_Name);
        $this->assertSame('+968 99112233', $fresh->Pickup_Person_Contact);
        $this->assertNotNull($fresh->Picked_Up_At);
        $this->assertSame($admin->id, (int) $fresh->Picked_Up_By);

        $lineStatus = DB::table('Orders_Placed_Details_T')->where('id', $lineId)->value('Status');
        $this->assertSame('delivered', $lineStatus);

        // ID image stored on the (fake) private local disk under PickupIds/{id}/.
        $this->assertNotNull($fresh->Pickup_Id_Image_Path);
        $this->assertStringStartsWith("PickupIds/{$order->id}/", $fresh->Pickup_Id_Image_Path);
        Storage::disk('private_uploads')->assertExists($fresh->Pickup_Id_Image_Path);

        // Process log records the collector.
        $log = OrderProcessLog::where('Orders_Placed_Id', $order->id)
            ->where('Step_Code', 'PICKUP_COLLECTED')
            ->latest('id')
            ->first();
        $this->assertNotNull($log, 'PICKUP_COLLECTED process log row missing.');
        $this->assertStringContainsString('Collected by: Ahmed Al Collector (+968 99112233)', $log->Notes);
    }

    // ---------------------------------------------------------------
    // (c) Non-pickup order => 404 (Delivery_Type guard)
    // ---------------------------------------------------------------

    public function test_pickup_complete_returns_404_for_non_pickup_order(): void
    {
        $this->actingAsAdmin();

        $order = $this->makeOrder(['Delivery_Type' => 'delivery', 'Status' => 'shipped']);

        $this->postJson("/api/orders-placed/{$order->id}/pickup-complete", [
            'pickup_person_name'    => 'Someone',
            'pickup_person_contact' => '99000000',
        ])->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Idempotency: an already-collected pickup order cannot be
    // re-completed (would overwrite the collector audit record and
    // orphan the stored ID image).
    // ---------------------------------------------------------------

    public function test_pickup_complete_rejects_already_collected_order(): void
    {
        $this->requirePickupColumns();
        $this->actingAsAdmin();

        Storage::fake('private_uploads');

        $order = $this->makeOrder([
            'Status'                => 'delivered',
            'Pickup_Person_Name'    => 'Original Collector',
            'Pickup_Person_Contact' => '91000000',
            'Picked_Up_At'          => now(),
        ]);

        $res = $this->post("/api/orders-placed/{$order->id}/pickup-complete", [
            'pickup_person_name'    => 'Impostor',
            'pickup_person_contact' => '92000000',
            'id_image'              => UploadedFile::fake()->create('other-id.jpg', 60, 'image/jpeg'),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(409);

        // Original collector record untouched, no new file stored.
        $fresh = $order->fresh();
        $this->assertSame('Original Collector', $fresh->Pickup_Person_Name);
        $this->assertSame('91000000', $fresh->Pickup_Person_Contact);
        $this->assertSame([], Storage::disk('private_uploads')->allFiles("PickupIds/{$order->id}"));
    }

    // ---------------------------------------------------------------
    // (e) pickup-id-url: 404 when there is no stored ID image / no order
    // ---------------------------------------------------------------

    public function test_pickup_id_url_returns_404_when_order_has_no_id_image(): void
    {
        $this->actingAsAdmin();

        $order = $this->makeOrder();

        $this->getJson("/api/orders-placed/{$order->id}/pickup-id-url")->assertNotFound();
    }

    public function test_pickup_id_url_returns_404_for_missing_order(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/orders-placed/999999999/pickup-id-url')->assertNotFound();
    }
}
