<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            //
               // who will ship & where this quote was computed for
            $table->unsignedBigInteger('Shippers_Id')->nullable()->after('Customers_Id');
            $table->unsignedBigInteger('Shippers_Destination_Id')->nullable()->after('Shippers_Id');

            // basis & price chosen by the user
            $table->string('Shipping_Basis', 10)->nullable()->after('Shippers_Destination_Id'); // weight|volume|heavy
            $table->decimal('Shipping_Price', 12, 3)->default(0)->after('Shipping_Basis');
            $table->string('Shipping_Currency', 3)->default('OMR')->after('Shipping_Price');

            // audit of what was used to quote (optional but useful)
            $table->decimal('Shipping_Weight_Kg', 10, 3)->nullable()->after('Shipping_Currency');
            $table->decimal('Shipping_Volume_Cbm', 12, 4)->nullable()->after('Shipping_Weight_Kg');

            // MSSQL: NO ACTION (no cascade) — omit cascade helpers
            $table->foreign('Shippers_Id')->references('id')->on('Shippers_Master_T');
            $table->foreign('Shippers_Destination_Id')->references('id')->on('Shipper_Destinations_T');
      
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('Orders_Placed_T', function (Blueprint $table) {
            $table->dropForeign(['Shippers_Id']);
            $table->dropForeign(['Shippers_Destination_Id']);
            $table->dropColumn([
                'Shippers_Id','Shippers_Destination_Id',
                'Shipping_Basis','Shipping_Price','Shipping_Currency',
                'Shipping_Weight_Kg','Shipping_Volume_Cbm'
            ]);
        });
    }
};
