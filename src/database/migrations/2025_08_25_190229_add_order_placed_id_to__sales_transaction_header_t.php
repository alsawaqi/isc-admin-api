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
        Schema::table('Sales_Transaction_Header_T', function (Blueprint $table) {
            //

             $table->unsignedBigInteger('Order_Placed_Id')->nullable()->after('Sales_Transaction_Header_code');

             $table->foreign('Order_Placed_Id')->references('id')->on('Orders_Placed_T')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Sales_Transaction_Header_T', function (Blueprint $table) {
            //
        });
    }
};
