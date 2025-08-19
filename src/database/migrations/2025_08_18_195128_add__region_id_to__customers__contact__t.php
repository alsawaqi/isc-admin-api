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
        Schema::table('Customers_Contact_T', function (Blueprint $table) {


            $table->unsignedBigInteger('Region_Id')->after('State_Id')->nullable();

            $table->foreign('Region_Id')
                ->references('id')->on('Geox_Region_Master_T')
                ->onDelete('no action');

            $table->unsignedBigInteger('District_Id')->after('Region_Id')->nullable();

            $table->foreign('District_Id')
                ->references('id')->on('Geox_District_Master_T')
                ->onDelete('no action');
             
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Customers_Contact_T', function (Blueprint $table) {
            //
        });
    }
};
