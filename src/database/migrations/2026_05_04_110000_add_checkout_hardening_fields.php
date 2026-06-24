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
            if (!Schema::hasColumn('Orders_Placed_T', 'Checkout_Request_Key')) {
                $table->string('Checkout_Request_Key', 120)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_T', 'Checkout_Submitted_At')) {
                $table->dateTime('Checkout_Submitted_At')->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_T', 'Payment_Status')) {
                $table->string('Payment_Status', 30)->default('unpaid');
            }

            if (!Schema::hasColumn('Orders_Placed_T', 'Payment_Method')) {
                $table->string('Payment_Method', 30)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_T', 'Shipping_Quote_Checked_At')) {
                $table->dateTime('Shipping_Quote_Checked_At')->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_T', 'Shipping_Quote_Expires_At')) {
                $table->dateTime('Shipping_Quote_Expires_At')->nullable();
            }
        });

        $this->createCheckoutRequestKeyIndex();

        Schema::table('Sales_Transactions_Details_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Sales_Transactions_Details_T', 'Payment_Gateway')) {
                $table->string('Payment_Gateway', 80)->nullable();
            }

            if (!Schema::hasColumn('Sales_Transactions_Details_T', 'Payment_Intent_Id')) {
                $table->string('Payment_Intent_Id', 160)->nullable();
            }

            if (!Schema::hasColumn('Sales_Transactions_Details_T', 'Payment_Idempotency_Key')) {
                $table->string('Payment_Idempotency_Key', 120)->nullable();
            }

            if (!Schema::hasColumn('Sales_Transactions_Details_T', 'Payment_Metadata')) {
                $table->text('Payment_Metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        $this->dropCheckoutRequestKeyIndex();

        Schema::table('Sales_Transactions_Details_T', function (Blueprint $table) {
            foreach ([
                'Payment_Gateway',
                'Payment_Intent_Id',
                'Payment_Idempotency_Key',
                'Payment_Metadata',
            ] as $column) {
                if (Schema::hasColumn('Sales_Transactions_Details_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            foreach ([
                'Checkout_Request_Key',
                'Checkout_Submitted_At',
                'Payment_Status',
                'Payment_Method',
                'Shipping_Quote_Checked_At',
                'Shipping_Quote_Expires_At',
            ] as $column) {
                if (Schema::hasColumn('Orders_Placed_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function createCheckoutRequestKeyIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlsrv') {
            DB::statement("
                IF NOT EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'ux_orders_checkout_request_key'
                      AND object_id = OBJECT_ID('dbo.Orders_Placed_T')
                )
                CREATE UNIQUE INDEX [ux_orders_checkout_request_key]
                ON [dbo].[Orders_Placed_T] ([Checkout_Request_Key])
                WHERE [Checkout_Request_Key] IS NOT NULL
            ");

            return;
        }

        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            $table->unique('Checkout_Request_Key', 'ux_orders_checkout_request_key');
        });
    }

    private function dropCheckoutRequestKeyIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlsrv') {
            DB::statement("
                IF EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'ux_orders_checkout_request_key'
                      AND object_id = OBJECT_ID('dbo.Orders_Placed_T')
                )
                DROP INDEX [ux_orders_checkout_request_key] ON [dbo].[Orders_Placed_T]
            ");

            return;
        }

        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            $table->dropUnique('ux_orders_checkout_request_key');
        });
    }
};
