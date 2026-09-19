<?php

namespace Tests\Unit\Vendors;

use App\Http\Controllers\AdminTempProductController;
use App\Models\ProductMaster;
use App\Models\ProductTemporary;
use App\Models\ProductVendorOffer;
use App\Models\ProductVendorRequest;
use App\Services\VendorOfferApproval;
use App\Services\VendorOffers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VendorOffersDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'offers_test', 'database.connections.offers_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('offers_test');
        DB::setDefaultConnection('offers_test');
        // Opt-in only for the disposable, unexposed SQL Server test container.
        if (getenv('VENDOR_OFFERS_TEST_SQLSRV') === '1') {
            config(['database.connections.offers_test' => [
                'driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => '1433',
                'database' => 'vendor_offer_isolation', 'username' => 'sa',
                'password' => getenv('VENDOR_OFFERS_TEST_PASSWORD'),
                'encrypt' => 'yes', 'trust_server_certificate' => 'true', 'prefix' => '',
            ]]);
            DB::purge('offers_test');
            Schema::dropAllTables();
        }
        Schema::create('Vendors_Master_T', function (Blueprint $t) {
            $t->id(); $t->string('Vendor_Name'); $t->boolean('Is_Active')->default(true);
        });
        Schema::create('Products_Master_T', function (Blueprint $t) {
            $t->id(); $t->string('Product_Name'); $t->string('Product_Code')->nullable(); $t->string('Slug')->nullable();
            $t->string('Product_Description')->nullable(); $t->integer('Vendor_Id')->nullable();
            foreach (['Product_Sub_Sub_Department_Id', 'Product_Type_Id', 'Product_Brand_Id', 'Product_Manufacture_Id'] as $c) { $t->integer($c)->nullable(); }
            foreach (['Product_Price', 'Product_Cost', 'Minimum_Selling_Price', 'Commission_Value'] as $c) { $t->decimal($c, 18, 3)->nullable(); }
            $t->string('Commission_Type')->nullable(); $t->integer('Product_Stock');
            $t->string('Status')->default('available'); $t->boolean('Is_Active')->default(true); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('Products_Temporary_T', function (Blueprint $t) {
            $t->id(); $t->integer('Vendor_Id'); $t->string('Product_Name'); $t->string('Description')->nullable();
            foreach (['Product_Sub_Sub_Department_Id', 'Product_Type_Id', 'Product_Brand_Id', 'Product_Manufacture_Id', 'Approved_Product_Id', 'Reviewed_By'] as $c) { $t->integer($c)->nullable(); }
            foreach (['Product_Price', 'Product_Cost', 'Commission_Value'] as $c) { $t->decimal($c, 18, 3)->nullable(); }
            $t->string('Commission_Type')->nullable(); $t->integer('Product_Stock'); $t->string('Submission_Status')->default('pending');
            $t->dateTime('Reviewed_At')->nullable(); $t->string('Rejection_Reason')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('Products_Vendor_Requests_T', function (Blueprint $t) {
            $t->id(); $t->integer('Products_Id')->nullable(); $t->integer('Vendor_Id'); $t->integer('Products_Temporary_Id')->nullable();
            foreach (['Request_Type', 'Status', 'Comment', 'Requested_Changes_Json', 'Action_By_Role'] as $c) { $t->text($c)->nullable(); }
            $t->integer('Action_By_User_Id')->nullable(); $t->dateTime('Action_At')->nullable(); $t->timestamps();
        });
        Schema::create('Customers_Carts_T', function (Blueprint $t) {
            $t->id(); $t->integer('Customers_Id'); $t->integer('Products_Id'); $t->integer('Quantity'); $t->timestamps(); $t->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX ux_customers_carts_active_customer_product ON Customers_Carts_T (Customers_Id, Products_Id) WHERE deleted_at IS NULL');
        Schema::create('Orders_Placed_Details_T', function (Blueprint $t) {
            $t->id(); $t->integer('Products_Id'); $t->integer('Vendor_Id')->nullable(); $t->decimal('Price', 18, 3);
        });
        foreach (['Products_Bulk_Prices_T' => 'Products_Id', 'Products_Temporary_Bulk_Prices_T' => 'Products_Temporary_Id'] as $name => $foreign) {
            Schema::create($name, function (Blueprint $t) use ($foreign) {
                $t->id(); $t->integer($foreign); $t->integer('Min_Qty'); $t->integer('Max_Qty')->nullable(); $t->decimal('Unit_Price', 18, 3); $t->timestamps();
            });
        }
        foreach (['Product_Specification_Product_T' => 'Product_Id', 'Product_Specification_Product_Temp_T' => 'Product_Temporary_Id'] as $name => $foreign) {
            Schema::create($name, function (Blueprint $t) use ($foreign) {
                $t->id(); $t->integer($foreign); $t->integer('Product_Specification_Description_Id'); $t->integer('product_specification_value_id'); $t->timestamps();
            });
        }
        Schema::create('Products_Temporary_Images_T', function (Blueprint $t) {
            $t->id(); $t->integer('Products_Temporary_Id'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('Products_Sub_Sub_Department_T', fn (Blueprint $t) => $t->id());
        DB::statement('INSERT INTO Products_Sub_Sub_Department_T DEFAULT VALUES');
        DB::table('Vendors_Master_T')->insert([['Vendor_Name' => 'Seller A'], ['Vendor_Name' => 'Seller B']]);
        DB::table('Products_Master_T')->insert([
            'Product_Name' => 'Drill 18V', 'Vendor_Id' => 1, 'Product_Price' => 12.345,
            'Product_Stock' => 7, 'Product_Sub_Sub_Department_Id' => 1, 'Product_Brand_Id' => 3,
            'Commission_Type' => 'fixed', 'Commission_Value' => 1.25, 'Product_Description' => 'Original shared description',
        ]);
        DB::table('Products_Bulk_Prices_T')->insert(['Products_Id' => 1, 'Min_Qty' => 3, 'Max_Qty' => null, 'Unit_Price' => 10.123]);
        DB::table('Customers_Carts_T')->insert(['Customers_Id' => 1, 'Products_Id' => 1, 'Quantity' => 2]);
        DB::table('Orders_Placed_Details_T')->insert(['Products_Id' => 1, 'Vendor_Id' => 1, 'Price' => 9.876]);
        (require database_path('migrations/2026_09_19_100000_create_product_vendor_offers.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('offers_test');
        parent::tearDown();
    }

    private function submission(array $attributes = []): ProductTemporary
    {
        return ProductTemporary::create(array_replace([
            'Vendor_Id' => 2, 'Product_Name' => 'Drill 18V', 'Description' => 'Vendor description must not replace master',
            'Product_Price' => 14.567, 'Product_Stock' => 20, 'Product_Sub_Sub_Department_Id' => 1,
            'Product_Brand_Id' => 3, 'Submission_Status' => 'pending',
        ], $attributes));
    }

    private function approve(ProductTemporary $temp, ?int $master = 1): int
    {
        return (new \ReflectionMethod(AdminTempProductController::class, 'approveOne'))
            ->invoke(app(AdminTempProductController::class), $temp, 'fixed', 2.5, $master);
    }

    public function test_new_identity_creates_one_master_and_its_first_seller_offer(): void
    {
        Schema::table('Products_Master_T', function (Blueprint $t) {
            foreach (['Product_Department_Id', 'Product_Sub_Department_Id', 'Created_By'] as $c) { $t->integer($c)->nullable(); }
            foreach (['Product_Name_Ar', 'Inhouse_Barcode_Source'] as $c) { $t->string($c)->nullable(); }
            foreach (['Weight_Kg', 'Length_Cm', 'Width_Cm', 'Height_Cm', 'Volume_Cbm'] as $c) { $t->decimal($c, 18, 4)->nullable(); }
            $t->dateTime('Created_Date')->nullable();
        });
        $temp = $this->submission(['Product_Name' => 'New 24V Hammer Drill']);
        DB::table('Products_Temporary_Bulk_Prices_T')->insert(['Products_Temporary_Id' => $temp->id, 'Min_Qty' => 3, 'Max_Qty' => null, 'Unit_Price' => 12.123]);
        $id = $this->approve($temp, null);
        $this->assertSame(2, ProductMaster::count());
        $master = ProductMaster::findOrFail($id);
        $offer = ProductVendorOffer::where('Products_Id', $id)->sole();
        $this->assertSame('New 24V Hammer Drill', $master->Product_Name);
        $this->assertNotEmpty($master->Product_Code);
        $this->assertEquals(2, $offer->Vendor_Id);
        $this->assertEquals(14.567, $offer->Product_Price);
        $this->assertEquals(2.5, $offer->Commission_Value);
        $this->assertEquals(12.123, $offer->bulkPrices()->sole()->Unit_Price);
        $this->assertSame(0, $master->bulkPrices()->count());
        $this->assertEquals($offer->id, ProductTemporary::withTrashed()->findOrFail($temp->id)->Vendor_Offer_Id);
    }

    public function test_backfill_preserves_prices_inventory_tiers_and_historical_amounts(): void
    {
        $offer = ProductVendorOffer::firstOrFail();
        $this->assertEquals(12.345, $offer->Product_Price);
        $this->assertSame(7, $offer->Product_Stock);
        $this->assertEquals(10.123, $offer->bulkPrices->first()->Unit_Price);
        $this->assertEquals($offer->id, DB::table('Customers_Carts_T')->value('Vendor_Offer_Id'));
        $this->assertEquals($offer->id, DB::table('Orders_Placed_Details_T')->value('Vendor_Offer_Id'));
        $this->assertEquals(9.876, DB::table('Orders_Placed_Details_T')->value('Price'));
    }

    public function test_migration_replaces_cart_uniqueness_and_keeps_archived_cart_rows(): void
    {
        $this->assertFalse(Schema::hasIndex('Customers_Carts_T', 'ux_customers_carts_active_customer_product'));
        DB::table('Customers_Carts_T')->insert(['Customers_Id' => 1, 'Products_Id' => 1, 'Vendor_Offer_Id' => 2, 'Quantity' => 3]);
        DB::table('Customers_Carts_T')->insert(['Customers_Id' => 1, 'Products_Id' => 1, 'Vendor_Offer_Id' => 1, 'Quantity' => 2, 'deleted_at' => now()]);
        $this->assertSame(3, DB::table('Customers_Carts_T')->count());
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('Customers_Carts_T')->insert(['Customers_Id' => 1, 'Products_Id' => 1, 'Vendor_Offer_Id' => 2, 'Quantity' => 1]);
    }

    public function test_return_restock_uses_the_original_order_line_seller(): void
    {
        Schema::create('Product_Stock_Movements_T', function (Blueprint $t) {
            $t->id();
            foreach (['Products_Id','Vendor_Id','Movement_Type','Quantity_Delta','Quantity','Previous_Stock','New_Stock','Actor_Type','Actor_Id','Actor_Name','Notes'] as $c) { $t->text($c)->nullable(); }
            $t->timestamps();
        });
        $this->approve($this->submission());
        $offer = VendorOffers::forVendor(1, 2);
        $detail = new \App\Models\OrdersPlacedDetails;
        $detail->forceFill(['id'=>9, 'Products_Id'=>1, 'Vendor_Id'=>2, 'Vendor_Offer_Id'=>$offer->id]);
        $service = new \App\Services\Orders\OrderReturnRefundService;
        (new \ReflectionMethod($service, 'restockProduct'))->invoke($service, $detail, 2, null, 'Customer return');
        $this->assertSame(22, $offer->fresh()->Product_Stock);
        $this->assertSame(7, VendorOffers::forVendor(1, 1)->Product_Stock);
        $this->assertEquals(2, DB::table('Product_Stock_Movements_T')->value('Vendor_Id'));
    }

    public function test_approval_links_second_seller_without_altering_master_or_first_offer(): void
    {
        $before = (array) DB::table('Products_Master_T')->first();
        $temp = $this->submission();
        DB::table('Products_Temporary_Bulk_Prices_T')->insert(['Products_Temporary_Id' => $temp->id, 'Min_Qty' => 5, 'Unit_Price' => 13.123]);
        $this->assertSame(1, $this->approve($temp));
        $this->assertSame(1, ProductMaster::count());
        $this->assertSame($before, (array) DB::table('Products_Master_T')->first());
        $second = VendorOffers::forVendor(1, 2);
        $this->assertEquals(14.567, $second->Product_Price);
        $this->assertEquals(13.123, $second->bulkPrices->first()->Unit_Price);
        $this->assertEquals(2.5, $second->Commission_Value);
        $this->assertEquals(12.345, VendorOffers::forVendor(1, 1)->Product_Price);
        $this->assertEquals($second->id, $temp->fresh()->Vendor_Offer_Id);
        $this->assertSame('approved', $temp->fresh()->Submission_Status);
        $this->assertEquals($second->id, ProductVendorRequest::first()->Vendor_Offer_Id);
    }

    public function test_same_master_expands_to_distinct_prices_and_vendor_read_projection_is_scoped(): void
    {
        $this->approve($this->submission());
        $list = VendorOffers::expand(ProductMaster::all());
        $this->assertCount(2, $list);
        $this->assertEquals([12.345, 14.567], $list->pluck('Product_Price')->all());
        $seller = VendorOffers::products(ProductMaster::class, 2)->firstOrFail();
        $this->assertSame('Seller B', $seller->Seller_Name);
        $this->assertEquals(14.567, $seller->Product_Price);
        $this->assertCount(1, VendorOffers::products(ProductMaster::class, 2)->get());
    }

    public function test_conflicting_brand_is_rejected_without_writes(): void
    {
        try { $this->approve($this->submission(['Product_Brand_Id' => 99])); $this->fail('Expected rejection'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('match_product_id', $e->errors()); }
        $this->assertSame(1, ProductVendorOffer::count());
        $this->assertSame(0, ProductVendorRequest::count());
    }

    public function test_conflicting_specification_is_rejected(): void
    {
        $temp = $this->submission();
        DB::table('Product_Specification_Product_T')->insert(['Product_Id' => 1, 'Product_Specification_Description_Id' => 4, 'product_specification_value_id' => 18]);
        DB::table('Product_Specification_Product_Temp_T')->insert(['Product_Temporary_Id' => $temp->id, 'Product_Specification_Description_Id' => 4, 'product_specification_value_id' => 24]);
        $this->expectException(ValidationException::class);
        $this->approve($temp);
    }

    public function test_duplicate_master_creation_is_blocked_and_vendor_cannot_link_twice(): void
    {
        try { $this->approve($this->submission(), null); $this->fail('Expected duplicate rejection'); }
        catch (ValidationException) { $this->assertSame(1, ProductMaster::count()); }
        $this->approve($this->submission());
        $this->expectException(ValidationException::class);
        $this->approve($this->submission());
    }

    public function test_invalid_tiers_roll_back_offer_and_approval_atomically(): void
    {
        $temp = $this->submission();
        DB::table('Products_Temporary_Bulk_Prices_T')->insert(['Products_Temporary_Id' => $temp->id, 'Min_Qty' => 0, 'Unit_Price' => 1]);
        try { $this->approve($temp); $this->fail('Expected invalid tier'); }
        catch (\InvalidArgumentException) { $this->assertSame(1, ProductVendorOffer::count()); }
        $this->assertSame('pending', $temp->fresh()->Submission_Status);
        $this->assertSame(0, ProductVendorRequest::count());
    }

    public function test_price_and_stock_update_changes_only_requesting_vendor(): void
    {
        $this->approve($this->submission());
        $row = ProductVendorRequest::create(['Products_Id' => 1, 'Vendor_Id' => 2, 'Request_Type' => 'approved_update', 'Status' => 'pending',
            'Requested_Changes_Json' => ['Product_Price' => 17.123, 'Product_Stock' => 9, 'Status' => 'available']]);
        (new \ReflectionMethod(AdminTempProductController::class, 'applyVendorOfferUpdate'))->invoke(app(AdminTempProductController::class), $row, null);
        $this->assertEquals(17.123, VendorOffers::forVendor(1, 2)->Product_Price);
        $this->assertSame(9, VendorOffers::forVendor(1, 2)->Product_Stock);
        $this->assertEquals(12.345, VendorOffers::forVendor(1, 1)->Product_Price);
        $this->assertEquals(12.345, ProductMaster::first()->Product_Price);
        $this->assertSame('approved', $row->fresh()->Status);
    }

    private function adminRequest(array $data): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/', 'PUT', $data);
        $request->setUserResolver(fn () => new class { public function can($permission) { return true; } });
        return $request;
    }

    public function test_admin_editor_updates_selected_offer_and_preserves_other_seller(): void
    {
        $this->approve($this->submission());
        $offer = VendorOffers::forVendor(1, 2);
        $request = $this->adminRequest(['vendor_offer_id'=>$offer->id, 'expected_stock'=>20, 'Product_Price'=>18.123, 'Product_Stock'=>19, 'Minimum_Selling_Price'=>8, 'Vendor_Id'=>2]);
        $response = (new \App\Http\Controllers\ProductMasterController)->update($request, ProductMaster::first());
        $this->assertEquals(18.123, $response->getData(true)['Product_Price']);
        $this->assertSame(19, $offer->fresh()->Product_Stock);
        $this->assertEquals(12.345, VendorOffers::forVendor(1, 1)->Product_Price);
        $this->assertEquals(12.345, ProductMaster::first()->Product_Price);
        $this->assertEquals(2.5, $offer->fresh()->Commission_Value);
    }

    public function test_admin_editor_rejects_stale_stock_without_changing_price(): void
    {
        $offer = VendorOffers::forVendor(1, 1);
        $request = $this->adminRequest(['vendor_offer_id'=>$offer->id, 'expected_stock'=>8, 'Product_Price'=>99, 'Product_Stock'=>8]);
        try { (new \App\Http\Controllers\ProductMasterController)->update($request, ProductMaster::first()); $this->fail('Stale stock must be rejected'); }
        catch (ValidationException) { $this->assertEquals(12.345, $offer->fresh()->Product_Price); }
    }

    public function test_shared_content_cannot_be_changed_through_offer_update(): void
    {
        $row = ProductVendorRequest::create(['Products_Id' => 1, 'Vendor_Id' => 1, 'Request_Type' => 'approved_update', 'Status' => 'pending',
            'Requested_Changes_Json' => ['Product_Name' => 'Replace every seller name']]);
        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionMethod(AdminTempProductController::class, 'applyVendorOfferUpdate'))->invoke(app(AdminTempProductController::class), $row, null);
    }

    public function test_checkout_resolution_and_stock_reference_do_not_use_canonical_owner(): void
    {
        $this->approve($this->submission());
        $offer = VendorOffers::forVendor(1, 2);
        $selected = VendorOffers::resolve(ProductMaster::first(), $offer->id, true);
        $this->assertEquals(2, $selected->Vendor_Id);
        $this->assertEquals(14.567, $selected->Product_Price);
        $this->assertEquals(2.5, $selected->Commission_Value);
        VendorOffers::stockRecord(1, $offer->id, 2)->decrement('Product_Stock', 3);
        $this->assertSame(17, $offer->fresh()->Product_Stock);
        $this->assertSame(7, VendorOffers::forVendor(1, 1)->Product_Stock);
        $this->assertEquals(7, ProductMaster::first()->Product_Stock);
        $this->assertNull(VendorOffers::stockRecord(1, $offer->id, 1)->first());
    }

    public function test_removed_offer_is_hidden_but_can_be_restocked_for_historical_order(): void
    {
        $this->approve($this->submission());
        $offer = VendorOffers::forVendor(1, 2); $offer->delete();
        $this->assertCount(1, VendorOffers::expand(ProductMaster::all()));
        $this->assertFalse(VendorOffers::owns(1, 2));
        VendorOffers::stockRecord(1, $offer->id, 2)->increment('Product_Stock', 2);
        $this->assertEquals(22, ProductVendorOffer::withTrashed()->find($offer->id)->Product_Stock);
        $this->expectException(ValidationException::class);
        VendorOffers::resolve(ProductMaster::first(), $offer->id);
    }

    public function test_inactive_seller_and_wrong_product_offer_are_rejected(): void
    {
        $offer = ProductVendorOffer::first();
        DB::table('Vendors_Master_T')->where('id', 1)->update(['Is_Active' => false]);
        $this->assertCount(0, VendorOffers::expand(ProductMaster::all()));
        $this->expectException(ValidationException::class);
        VendorOffers::resolve(ProductMaster::first(), $offer->id);
    }
}
