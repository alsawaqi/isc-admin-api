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
        Schema::table('Customers_Carts_T', function (Blueprint $table) {
            //
                 $table->unique(['Customers_Id', 'Products_Id'], 'ux_customers_carts_customer_product');
        
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers_carts_t', function (Blueprint $table) {
            //
        });
    }
};
