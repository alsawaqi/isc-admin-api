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

            $table->unsignedBigInteger('Sales_Transaction_Header_Id')->nullable()->after('Sales_Transactions_Details_code');
            $table->foreign('Sales_Transaction_Header_Id')->references('id')->on('Sales_Transaction_Header_T')->onDelete('no action');
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
