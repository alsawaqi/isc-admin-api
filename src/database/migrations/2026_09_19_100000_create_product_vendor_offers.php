<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(fn () => $this->createAndBackfill());
    }

    private function createAndBackfill(): void
    {
        Schema::create('Products_Vendor_Offers_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Products_Id')->index();
            $table->unsignedBigInteger('Vendor_Id')->index();
            $table->decimal('Product_Price', 18, 3);
            $table->decimal('Product_Cost', 18, 3)->nullable();
            $table->decimal('Minimum_Selling_Price', 18, 3)->nullable();
            $table->integer('Product_Stock')->default(0);
            $table->string('Status', 30)->default('available');
            $table->boolean('Is_Active')->default(true);
            $table->string('Commission_Type', 20)->nullable();
            $table->decimal('Commission_Value', 18, 3)->nullable();
            $table->unsignedBigInteger('Approved_By')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['Products_Id', 'Vendor_Id'], 'product_vendor_offer_unique');
        });
        Schema::create('Products_Vendor_Offer_Bulk_Prices_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Vendor_Offer_Id')->index();
            $table->integer('Min_Qty');
            $table->integer('Max_Qty')->nullable();
            $table->decimal('Unit_Price', 18, 3);
            $table->timestamps();
        });
        foreach (['Customers_Carts_T', 'Orders_Placed_Details_T', 'Products_Temporary_T', 'Products_Vendor_Requests_T'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('Vendor_Offer_Id')->nullable()->index());
        }

        // The old active-cart uniqueness allowed only one seller per product.
        // Keep separate filtered keys for platform and vendor offers so NULL
        // behaves consistently on SQL Server and SQLite, and archived carts survive.
        foreach (['ux_customers_carts_customer_product', 'ux_customers_carts_active_customer_product'] as $index) {
            if (DB::getDriverName() === 'sqlsrv') {
                DB::statement("IF EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'{$index}' AND parent_object_id = OBJECT_ID(N'dbo.Customers_Carts_T'))
                    ALTER TABLE [dbo].[Customers_Carts_T] DROP CONSTRAINT [{$index}];
                    IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'{$index}' AND object_id = OBJECT_ID(N'dbo.Customers_Carts_T'))
                    DROP INDEX [{$index}] ON [dbo].[Customers_Carts_T];");
            } elseif (Schema::hasIndex('Customers_Carts_T', $index)) {
                Schema::table('Customers_Carts_T', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
        DB::statement('CREATE UNIQUE INDEX ux_cart_active_vendor_offer ON Customers_Carts_T (Customers_Id, Products_Id, Vendor_Offer_Id) WHERE deleted_at IS NULL AND Vendor_Offer_Id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ux_cart_active_platform_product ON Customers_Carts_T (Customers_Id, Products_Id) WHERE deleted_at IS NULL AND Vendor_Offer_Id IS NULL');

        // Copy existing seller inventories without merging products or changing financial history.
        // Deployment must pause catalogue/cart/order writes for this atomic backfill.
        DB::transaction(function () {
            DB::table('Products_Master_T')->whereNotNull('Vendor_Id')->where('Vendor_Id', '>', 0)->orderBy('id')->chunkById(200, function ($products) {
                foreach ($products as $product) {
                    $offerId = DB::table('Products_Vendor_Offers_T')->insertGetId([
                        'Products_Id' => $product->id, 'Vendor_Id' => $product->Vendor_Id,
                        'Product_Price' => $product->Product_Price, 'Product_Cost' => $product->Product_Cost ?? null,
                        'Minimum_Selling_Price' => $product->Minimum_Selling_Price ?? null,
                        'Product_Stock' => $product->Product_Stock ?? 0, 'Status' => $product->Status ?? 'available',
                        'Is_Active' => $product->Is_Active ?? true,
                        'deleted_at' => $product->deleted_at ?? null,
                        'Commission_Type' => $product->Commission_Type ?? null, 'Commission_Value' => $product->Commission_Value ?? null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (Schema::hasTable('Products_Bulk_Prices_T')) {
                        foreach (DB::table('Products_Bulk_Prices_T')->where('Products_Id', $product->id)->get() as $tier) {
                            DB::table('Products_Vendor_Offer_Bulk_Prices_T')->insert([
                                'Vendor_Offer_Id' => $offerId, 'Min_Qty' => $tier->Min_Qty, 'Max_Qty' => $tier->Max_Qty,
                                'Unit_Price' => $tier->Unit_Price, 'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                    }
                    DB::table('Customers_Carts_T')->where('Products_Id', $product->id)->update(['Vendor_Offer_Id' => $offerId]);
                    DB::table('Orders_Placed_Details_T')->where('Products_Id', $product->id)->where('Vendor_Id', $product->Vendor_Id)->update(['Vendor_Offer_Id' => $offerId]);
                    DB::table('Products_Temporary_T')->where('Approved_Product_Id', $product->id)->where('Vendor_Id', $product->Vendor_Id)->update(['Vendor_Offer_Id' => $offerId]);
                    DB::table('Products_Vendor_Requests_T')->where('Products_Id', $product->id)->where('Vendor_Id', $product->Vendor_Id)->update(['Vendor_Offer_Id' => $offerId]);
                }
            });
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Vendor offers may have independent stock and orders. Restore a verified coordinated backup; do not drop seller mappings.');
    }
};
