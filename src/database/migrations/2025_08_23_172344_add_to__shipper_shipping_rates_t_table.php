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
        Schema::table('Shipper_Shipping_Rates_T', function (Blueprint $table) {
            //
              $table->boolean('Shippers_Destination_Rate_Box')
                  ->default(false)
                  ->after('Shippers_Destination_Rate_Applicable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Shipper_Shipping_Rates_T', function (Blueprint $table) {
            //
        });
    }
};
