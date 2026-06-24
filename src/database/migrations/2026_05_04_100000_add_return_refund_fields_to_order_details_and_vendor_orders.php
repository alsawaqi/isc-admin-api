<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Sold_Amount')) {
                $table->decimal('Sold_Amount', 18, 3)->default(0);
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Returned_Quantity')) {
                $table->integer('Returned_Quantity')->default(0);
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Refunded_Amount')) {
                $table->decimal('Refunded_Amount', 18, 3)->default(0);
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Net_Amount')) {
                $table->decimal('Net_Amount', 18, 3)->default(0);
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Return_State')) {
                $table->string('Return_State', 30)->default('not_returned');
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Refund_State')) {
                $table->string('Refund_State', 30)->default('not_refunded');
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Last_Returned_At')) {
                $table->dateTime('Last_Returned_At')->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Last_Refunded_At')) {
                $table->dateTime('Last_Refunded_At')->nullable();
            }
        });

        DB::table('Orders_Placed_Details_T')->update([
            'Sold_Amount' => DB::raw('COALESCE(NULLIF([Sold_Amount], 0), NULLIF([Subtotal], 0), [Price] * [Quantity], 0)'),
            'Net_Amount' => DB::raw('COALESCE(NULLIF([Net_Amount], 0), NULLIF([Subtotal], 0), [Price] * [Quantity], 0)'),
            'Returned_Quantity' => DB::raw('COALESCE([Returned_Quantity], 0)'),
            'Refunded_Amount' => DB::raw('COALESCE([Refunded_Amount], 0)'),
            'Return_State' => DB::raw("COALESCE([Return_State], 'not_returned')"),
            'Refund_State' => DB::raw("COALESCE([Refund_State], 'not_refunded')"),
        ]);

        Schema::table('Orders_Placed_Vendors_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Returned_Quantity')) {
                $table->integer('Returned_Quantity')->default(0);
            }

            if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Refunded_Amount')) {
                $table->decimal('Refunded_Amount', 18, 3)->default(0);
            }

            if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Net_Sub_Total')) {
                $table->decimal('Net_Sub_Total', 18, 3)->default(0);
            }

            if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Adjusted_Commission_Amount')) {
                $table->decimal('Adjusted_Commission_Amount', 18, 3)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Net_Payout_Amount')) {
                $table->decimal('Net_Payout_Amount', 18, 3)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Vendors_T', 'Payout_Adjustment_Amount')) {
                $table->decimal('Payout_Adjustment_Amount', 18, 3)->default(0);
            }
        });

        DB::table('Orders_Placed_Vendors_T')->update([
            'Returned_Quantity' => DB::raw('COALESCE([Returned_Quantity], 0)'),
            'Refunded_Amount' => DB::raw('COALESCE([Refunded_Amount], 0)'),
            'Net_Sub_Total' => DB::raw('COALESCE(NULLIF([Net_Sub_Total], 0), [Sub_Total], 0)'),
            'Adjusted_Commission_Amount' => DB::raw('COALESCE([Adjusted_Commission_Amount], [Commission_Amount])'),
            'Net_Payout_Amount' => DB::raw('COALESCE([Net_Payout_Amount], [Payout_Amount], COALESCE([Sub_Total], 0) - COALESCE([Commission_Amount], 0))'),
            'Payout_Adjustment_Amount' => DB::raw('COALESCE([Payout_Adjustment_Amount], 0)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('Orders_Placed_Vendors_T', function (Blueprint $table) {
            foreach ([
                'Returned_Quantity',
                'Refunded_Amount',
                'Net_Sub_Total',
                'Adjusted_Commission_Amount',
                'Net_Payout_Amount',
                'Payout_Adjustment_Amount',
            ] as $column) {
                if (Schema::hasColumn('Orders_Placed_Vendors_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            foreach ([
                'Sold_Amount',
                'Returned_Quantity',
                'Refunded_Amount',
                'Net_Amount',
                'Return_State',
                'Refund_State',
                'Last_Returned_At',
                'Last_Refunded_At',
            ] as $column) {
                if (Schema::hasColumn('Orders_Placed_Details_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
