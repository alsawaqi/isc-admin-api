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
        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            $table->bigInteger('Vendor_Id')->nullable()->after('Products_Id');
            $table->index(['Vendor_Id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            //
        });
    }
};
