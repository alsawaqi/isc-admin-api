<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Payment_Gateway_Attempts_T')) {
            Schema::create('Payment_Gateway_Attempts_T', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('Orders_Placed_Id');
                $table->unsignedBigInteger('Sales_Transactions_Details_Id')->nullable();
                $table->string('Gateway', 50);
                $table->string('Merchant_Reference', 120);
                $table->decimal('Amount', 18, 3);
                $table->char('Currency', 3)->default('OMR');
                $table->string('Currency_Id', 3)->default('512');
                $table->string('Status', 30)->default('pending');
                $table->string('Gateway_Transaction_Id', 191)->nullable();
                $table->string('Response_Code', 20)->nullable();
                $table->string('Response_Message', 500)->nullable();
                $table->string('Paid_Through', 50)->nullable();
                $table->dateTime('Initiated_At');
                $table->dateTime('Completed_At')->nullable();
                $table->dateTime('Last_Notification_At')->nullable();
                $table->text('Metadata')->nullable();
                $table->timestamps();

                $table->unique('Merchant_Reference', 'ux_payment_attempt_merchant_ref');
                $table->index(['Orders_Placed_Id', 'Status'], 'idx_payment_attempt_order_status');
                $table->index('Sales_Transactions_Details_Id', 'idx_payment_attempt_sales_detail');
            });

            $this->createGatewayTransactionIndex();
        }

        if (!Schema::hasTable('Payment_Gateway_Events_T')) {
            Schema::create('Payment_Gateway_Events_T', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('Payment_Gateway_Attempt_Id');
                $table->unsignedBigInteger('Orders_Placed_Id');
                $table->string('Gateway', 50);
                $table->string('Source', 20);
                $table->char('Payload_Digest', 64);
                $table->string('Merchant_Reference', 120);
                $table->string('Gateway_Transaction_Id', 191)->nullable();
                $table->string('Response_Code', 20)->nullable();
                $table->string('Outcome', 30);
                $table->dateTime('Processed_At');
                $table->timestamps();

                $table->unique('Payload_Digest', 'ux_payment_event_payload_digest');
                $table->index('Payment_Gateway_Attempt_Id', 'idx_payment_event_attempt');
                $table->index('Orders_Placed_Id', 'idx_payment_event_order');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('Payment_Gateway_Events_T');

        if (Schema::hasTable('Payment_Gateway_Attempts_T')) {
            $this->dropGatewayTransactionIndex();
            Schema::drop('Payment_Gateway_Attempts_T');
        }
    }

    private function createGatewayTransactionIndex(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement("
                IF NOT EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'ux_payment_attempt_gateway_txn'
                      AND object_id = OBJECT_ID('dbo.Payment_Gateway_Attempts_T')
                )
                CREATE UNIQUE INDEX [ux_payment_attempt_gateway_txn]
                ON [dbo].[Payment_Gateway_Attempts_T] ([Gateway_Transaction_Id])
                WHERE [Gateway_Transaction_Id] IS NOT NULL
            ");

            return;
        }

        Schema::table('Payment_Gateway_Attempts_T', function (Blueprint $table) {
            $table->unique('Gateway_Transaction_Id', 'ux_payment_attempt_gateway_txn');
        });
    }

    private function dropGatewayTransactionIndex(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement("
                IF EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'ux_payment_attempt_gateway_txn'
                      AND object_id = OBJECT_ID('dbo.Payment_Gateway_Attempts_T')
                )
                DROP INDEX [ux_payment_attempt_gateway_txn]
                ON [dbo].[Payment_Gateway_Attempts_T]
            ");

            return;
        }

        Schema::table('Payment_Gateway_Attempts_T', function (Blueprint $table) {
            $table->dropUnique('ux_payment_attempt_gateway_txn');
        });
    }
};
