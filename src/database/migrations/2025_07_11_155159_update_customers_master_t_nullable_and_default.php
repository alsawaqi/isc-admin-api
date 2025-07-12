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
        //
         Schema::table('Customers_Master_T', function (Blueprint $table) {
            $table->unsignedBigInteger('Customer_Type_Id')->nullable()->change();
            $table->unsignedBigInteger('Country_Id')->nullable()->change();
            $table->unsignedBigInteger('Region_Id')->nullable()->change();
            $table->unsignedBigInteger('Location_Id')->nullable()->change();
            $table->integer('Loyalty_Points')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
         Schema::table('Customers_Master_T', function (Blueprint $table) {
            $table->unsignedBigInteger('Customer_Type_Id')->nullable(false)->change();
            $table->unsignedBigInteger('Country_Id')->nullable(false)->change();
            $table->unsignedBigInteger('Region_Id')->nullable(false)->change();
            $table->unsignedBigInteger('Location_Id')->nullable(false)->change();
            $table->integer('Loyalty_Points')->default(null)->change(); // or ->nullable() if it was nullable before
        });
    }
};
