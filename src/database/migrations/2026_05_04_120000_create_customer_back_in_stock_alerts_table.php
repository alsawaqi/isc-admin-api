<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Customer_Back_In_Stock_Alerts_T')) {
            Schema::create('Customer_Back_In_Stock_Alerts_T', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('Products_Id');
                $table->unsignedBigInteger('User_Id');
                $table->unsignedBigInteger('Customer_Id')->nullable();
                $table->string('Product_Name', 255)->nullable();
                $table->string('Product_Slug', 255)->nullable();
                $table->string('Status', 20)->default('active');
                $table->dateTime('Notified_At')->nullable();
                $table->timestamps();

                $table->index(['Products_Id', 'Status'], 'idx_back_stock_product_status');
                $table->index(['User_Id', 'Status'], 'idx_back_stock_user_status');
            });
        }

        $this->createActiveAlertIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists('Customer_Back_In_Stock_Alerts_T');
    }

    private function createActiveAlertIndex(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement("
                IF NOT EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'ux_customer_active_back_stock_alert'
                      AND object_id = OBJECT_ID('dbo.Customer_Back_In_Stock_Alerts_T')
                )
                CREATE UNIQUE INDEX [ux_customer_active_back_stock_alert]
                ON [dbo].[Customer_Back_In_Stock_Alerts_T] ([Products_Id], [User_Id], [Status])
                WHERE [Status] = 'active'
            ");

            return;
        }

        Schema::table('Customer_Back_In_Stock_Alerts_T', function (Blueprint $table) {
            $table->unique(['Products_Id', 'User_Id', 'Status'], 'ux_customer_active_back_stock_alert');
        });
    }
};
