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
        Schema::table('Shipper_Destinations_T', function (Blueprint $table) {

            $table->dropUnique('uniq_shipper_destination_label');
            $table->dropColumn([
                'Shippers_Destination_Country',
                'Shippers_Destination_Region',
                'Shippers_Destination_District',
            ]);
        });
      
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
          Schema::table('Shipper_Destinations_T', function (Blueprint $table) {

            $table->dropUnique('uniq_shipper_destination_label');
            $table->dropColumn([
                'Shippers_Destination_Country',
                'Shippers_Destination_Region',
                'Shippers_Destination_District',
            ]);
        });
    }
};
