<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Orders_Placed_T', 'Loyalty_Points_Redeemed')) {
                $table->integer('Loyalty_Points_Redeemed')->default(0)->after('Shipping_Volume_Cbm');
            }

            if (!Schema::hasColumn('Orders_Placed_T', 'Loyalty_Discount_Amount')) {
                $table->decimal('Loyalty_Discount_Amount', 12, 3)->default(0)->after('Loyalty_Points_Redeemed');
            }
        });

        Schema::table('Customers_Loyalty_Transactions_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Customers_Loyalty_Transactions_T', 'Redeemed_Amount')) {
                $table->decimal('Redeemed_Amount', 12, 3)->default(0)->after('Points_Redeemed');
            }
        });

        DB::statement("
            UPDATE Orders_Placed_T
            SET
                Loyalty_Points_Redeemed = COALESCE(Loyalty_Points_Redeemed, 0),
                Loyalty_Discount_Amount = COALESCE(Loyalty_Discount_Amount, 0)
        ");

        DB::statement("
            UPDATE Customers_Loyalty_Transactions_T
            SET Redeemed_Amount = COALESCE(Redeemed_Amount, 0)
        ");
    }

    public function down(): void
    {
        Schema::table('Customers_Loyalty_Transactions_T', function (Blueprint $table) {
            if (Schema::hasColumn('Customers_Loyalty_Transactions_T', 'Redeemed_Amount')) {
                $table->dropColumn('Redeemed_Amount');
            }
        });

        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            if (Schema::hasColumn('Orders_Placed_T', 'Loyalty_Discount_Amount')) {
                $table->dropColumn('Loyalty_Discount_Amount');
            }

            if (Schema::hasColumn('Orders_Placed_T', 'Loyalty_Points_Redeemed')) {
                $table->dropColumn('Loyalty_Points_Redeemed');
            }
        });
    }
};
