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
        Schema::table('Sales_Transactions_Details_T', function (Blueprint $table) {
    
            $table->string('Payment_Method', 20)->nullable();   // 'card' | 'cod' | 'transfer'
            $table->string('Payment_Status', 30)->nullable();   // 'pending','authorized','captured','failed','refunded', etc.
            $table->decimal('Payment_Amount', 18, 3)->nullable();
            $table->char('Payment_Currency', 3)->nullable();    // e.g., 'OMR'

            // ---- Card-specific ----
            $table->string('Card_Brand', 20)->nullable();       // visa | mastercard | amex
            $table->string('Card_Last4', 4)->nullable();
            $table->tinyInteger('Card_Exp_Month')->unsigned()->nullable();
            $table->smallInteger('Card_Exp_Year')->unsigned()->nullable();
            $table->string('Card_Gateway', 50)->nullable();     // e.g., 'Network Intl', 'Stripe'
            $table->string('Card_Transaction_Id', 191)->nullable();
            $table->string('Card_Auth_Code', 100)->nullable();
            $table->string('Card_Error_Code', 100)->nullable();
            $table->text('Card_Error_Message')->nullable();

            // ---- COD-specific ----
            $table->boolean('COD_Collected')->nullable();
            $table->dateTime('COD_Collected_At')->nullable();
            $table->string('COD_Note', 300)->nullable();

            // ---- Bank Transfer-specific ----
            $table->string('Transfer_Reference', 120)->nullable();
            $table->string('Transfer_Payer_Name', 150)->nullable();
            $table->string('Transfer_Bank_Name', 150)->nullable();
            $table->string('Transfer_IBAN', 34)->nullable();
            $table->dateTime('Transfer_Received_At')->nullable();
            $table->string('Transfer_Slip_Path', 255)->nullable();

          
            $table->index('Payment_Method', 'idx_sales_details_payment_method');
            $table->index('Card_Transaction_Id', 'idx_sales_details_card_txn_id');
            $table->index('Transfer_Reference', 'idx_sales_details_transfer_ref');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Sales_Transactions_Details_T', function (Blueprint $table) {
            //
        });
    }
};
