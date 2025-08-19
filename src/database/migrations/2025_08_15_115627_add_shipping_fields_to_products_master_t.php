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
        Schema::table('Products_Master_T', function (Blueprint $table) {
            $table->decimal('Weight_Kg', 10, 3)->nullable();
            $table->decimal('Length_Cm', 10, 2)->nullable();
            $table->decimal('Width_Cm', 10, 2)->nullable();
            $table->decimal('Height_Cm', 10, 2)->nullable();
            $table->decimal('Volume_Cbm', 12, 6)->nullable(); // (L*W*H)/1,000,000
            $table->unsignedInteger('Units_Per_Carton')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Products_Master_T', function (Blueprint $table) {
            $table->dropColumn([
                'weight_kg','length_cm','width_cm','height_cm','volume_cbm','units_per_carton'
            ]);
        });
    }
};
