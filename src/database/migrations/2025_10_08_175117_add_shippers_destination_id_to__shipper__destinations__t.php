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
        Schema::table('Shipper_Destinations_T', function (Blueprint $table) {
            $table->unsignedBigInteger('Shippers_Destination_Country_Id')->nullable()->after('Shippers_Id'); 


            $table->foreign('Shippers_Destination_Country_Id')
                ->references('id')->on('Geox_Country_Master_T')
                ->onDelete('no action');


            $table->unsignedBigInteger('Shippers_Destination_Region_Id')->nullable()->after('Shippers_Destination_Country_Id');
            $table->foreign('Shippers_Destination_Region_Id')
                ->references('id')->on('Geox_Region_Master_T')
                ->onDelete('no action');

            $table->unsignedBigInteger('Shippers_Destination_District_Id')->nullable()->after('Shippers_Destination_Region_Id');
            $table->foreign('Shippers_Destination_District_Id')
                ->references('id')->on('Geox_District_Master_T')
                ->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Shipper_Destinations_T', function (Blueprint $table) {
            //
        });
    }
};
