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
        Schema::table('Order_Process_Log_T', function (Blueprint $table) {
             
            $table->unsignedBigInteger('Orders_Placed_Details_Cancelled_Id')
                  ->nullable()
                  ->after('Orders_Placed_Id');
                  
            $table->foreign('Orders_Placed_Details_Cancelled_Id')
                    ->nullable()
                    ->after('Orders_Placed_Id')
                    ->references('id')
                    ->on('Orders_Placed_Details_Cancelled_T')
                    ->onDelete('no action');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Order_Process_Log_T', function (Blueprint $table) {
            //
        });
    }
};
