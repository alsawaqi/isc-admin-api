<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantity-tier bulk pricing tables.
 *
 * DEPLOY ORDER: run this migration (isc-admin-api) FIRST. laravel-api
 * (storefront cart/checkout) and isc-multivendor-api (vendor portal) read
 * these tables behind Schema::hasTable guards, so they gracefully no-op
 * until this has run — but the feature only works once the tables exist.
 *
 * Tier semantics: Min_Qty..Max_Qty inclusive; Max_Qty NULL = open-ended
 * ("and above"). Replace-set writes (no soft deletes on tiers).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Products_Bulk_Prices_T')) {
            Schema::create('Products_Bulk_Prices_T', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('Products_Id');
                $table->integer('Min_Qty');
                $table->integer('Max_Qty')->nullable();
                $table->decimal('Unit_Price', 18, 3);
                $table->unsignedBigInteger('Created_By')->nullable();
                $table->timestamps();

                $table->index('Products_Id', 'idx_bulk_prices_product');
            });
        }

        if (!Schema::hasTable('Products_Temporary_Bulk_Prices_T')) {
            Schema::create('Products_Temporary_Bulk_Prices_T', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('Products_Temporary_Id');
                $table->integer('Min_Qty');
                $table->integer('Max_Qty')->nullable();
                $table->decimal('Unit_Price', 18, 3);
                $table->unsignedBigInteger('Created_By')->nullable();
                $table->timestamps();

                $table->index('Products_Temporary_Id', 'idx_temp_bulk_prices_temp');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('Products_Temporary_Bulk_Prices_T');
        Schema::dropIfExists('Products_Bulk_Prices_T');
    }
};
