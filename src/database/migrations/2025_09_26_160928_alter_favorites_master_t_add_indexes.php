<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
       public function up(): void
    {
        Schema::table('Favorites_Master_T', function (Blueprint $table) {
            // add indexes if not present
            $table->index('Customers_Id', 'fav_customer_idx');
            $table->index('Products_Id',  'fav_product_idx');

            // prevent duplicates per (customer, product)
            $table->unique(['Customers_Id','Products_Id'], 'fav_unique_customer_product');
        });
    }

    public function down(): void
    {
        Schema::table('Favorites_Master_T', function (Blueprint $table) {
            $table->dropUnique('fav_unique_customer_product');
            $table->dropIndex('fav_customer_idx');
            $table->dropIndex('fav_product_idx');
        });
    }
};
