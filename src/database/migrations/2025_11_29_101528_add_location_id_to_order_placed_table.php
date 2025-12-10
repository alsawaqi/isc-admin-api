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
        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('Location_Id')->nullable()->after('Order_Status');
            $table->foreign('Location_Id')->references('id')->on('Geox_Location_Master_T');
            $table->string('Delivery_Type')->nullable()->after('Location_Id');  

            $table->index('Location_Id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            //
        });
    }
};
