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
        Schema::table('Customers_Types_Master_T', function (Blueprint $table) {
            //
            $table->string('Customer_Type_Name')->after('Customer_Type_Code')->nullable();
            $table->string('Customer_Type_Description')->after('Customer_Type_Name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Customers_Types_Master_T', function (Blueprint $table) {
            //
        });
    }
};
