<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product cost / minimum-selling floor / deactivate / soft delete.
 *
 * ⚠️ DEPLOY-ORDER WARNING (VPS):
 * The admin, storefront (laravel-api) and multivendor ProductMaster/Products
 * models gain the SoftDeletes trait in the same change-set. SoftDeletes adds
 * "WHERE deleted_at IS NULL" to EVERY Eloquent query on Products_Master_T, so
 * if the new code goes live before this migration has run, every product
 * query on all three backends 500s (invalid column name 'deleted_at').
 * On the VPS this migration MUST be run BEFORE deploying the new
 * admin-api / laravel-api / multivendor-api code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Products_Master_T')) {
            Schema::table('Products_Master_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Products_Master_T', 'Product_Cost')) {
                    $table->decimal('Product_Cost', 18, 3)->nullable();
                }

                if (!Schema::hasColumn('Products_Master_T', 'Minimum_Selling_Price')) {
                    $table->decimal('Minimum_Selling_Price', 18, 3)->nullable();
                }

                if (!Schema::hasColumn('Products_Master_T', 'Is_Active')) {
                    $table->boolean('Is_Active')->default(true);
                }

                if (!Schema::hasColumn('Products_Master_T', 'deleted_at')) {
                    $table->dateTime('deleted_at')->nullable();
                }
            });
        }

        // Products_Temporary_T already has Product_Cost + deleted_at.
        if (Schema::hasTable('Products_Temporary_T')) {
            Schema::table('Products_Temporary_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Products_Temporary_T', 'Minimum_Selling_Price')) {
                    $table->decimal('Minimum_Selling_Price', 18, 3)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('Products_Temporary_T')) {
            Schema::table('Products_Temporary_T', function (Blueprint $table) {
                if (Schema::hasColumn('Products_Temporary_T', 'Minimum_Selling_Price')) {
                    $table->dropColumn('Minimum_Selling_Price');
                }
            });
        }

        if (Schema::hasTable('Products_Master_T')) {
            Schema::table('Products_Master_T', function (Blueprint $table) {
                foreach ([
                    'Product_Cost',
                    'Minimum_Selling_Price',
                    'Is_Active',
                    'deleted_at',
                ] as $column) {
                    if (Schema::hasColumn('Products_Master_T', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
