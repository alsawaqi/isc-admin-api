<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-product commission (set by admin at temp-product approval time)
        if (Schema::hasTable('Products_Temporary_T')) {
            Schema::table('Products_Temporary_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Products_Temporary_T', 'Commission_Type')) {
                    $table->string('Commission_Type', 10)->nullable();
                }

                if (!Schema::hasColumn('Products_Temporary_T', 'Commission_Value')) {
                    $table->decimal('Commission_Value', 18, 3)->nullable();
                }
            });
        }

        if (Schema::hasTable('Products_Master_T')) {
            Schema::table('Products_Master_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Products_Master_T', 'Commission_Type')) {
                    $table->string('Commission_Type', 10)->nullable();
                }

                if (!Schema::hasColumn('Products_Master_T', 'Commission_Value')) {
                    $table->decimal('Commission_Value', 18, 3)->nullable();
                }
            });
        }

        // Per-line commission snapshot taken at checkout
        if (Schema::hasTable('Orders_Placed_Details_T')) {
            Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Orders_Placed_Details_T', 'Commission_Type')) {
                    $table->string('Commission_Type', 10)->nullable();
                }

                if (!Schema::hasColumn('Orders_Placed_Details_T', 'Commission_Value')) {
                    $table->decimal('Commission_Value', 18, 3)->nullable();
                }

                if (!Schema::hasColumn('Orders_Placed_Details_T', 'Commission_Amount')) {
                    $table->decimal('Commission_Amount', 18, 3)->nullable();
                }
            });
        }

        // Vendor-order commission provenance + accountant confirmation audit
        if (Schema::hasTable('Orders_Placed_Vendors_T')) {
            Schema::table('Orders_Placed_Vendors_T', function (Blueprint $table) {
                if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Commission_Source')) {
                    $table->string('Commission_Source', 20)->nullable();
                }

                if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Commission_Confirmed_By')) {
                    $table->unsignedBigInteger('Commission_Confirmed_By')->nullable();
                }

                if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Commission_Confirmed_At')) {
                    $table->dateTime('Commission_Confirmed_At')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('Orders_Placed_Vendors_T')) {
            Schema::table('Orders_Placed_Vendors_T', function (Blueprint $table) {
                foreach ([
                    'Commission_Source',
                    'Commission_Confirmed_By',
                    'Commission_Confirmed_At',
                ] as $column) {
                    if (Schema::hasColumn('Orders_Placed_Vendors_T', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('Orders_Placed_Details_T')) {
            Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
                foreach ([
                    'Commission_Type',
                    'Commission_Value',
                    'Commission_Amount',
                ] as $column) {
                    if (Schema::hasColumn('Orders_Placed_Details_T', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('Products_Master_T')) {
            Schema::table('Products_Master_T', function (Blueprint $table) {
                foreach (['Commission_Type', 'Commission_Value'] as $column) {
                    if (Schema::hasColumn('Products_Master_T', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('Products_Temporary_T')) {
            Schema::table('Products_Temporary_T', function (Blueprint $table) {
                foreach (['Commission_Type', 'Commission_Value'] as $column) {
                    if (Schema::hasColumn('Products_Temporary_T', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
