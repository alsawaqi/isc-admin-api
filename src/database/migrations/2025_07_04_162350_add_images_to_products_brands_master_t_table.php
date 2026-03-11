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
        Schema::table('Products_Brands_Master_T', function (Blueprint $table) {
            //
            $table->string('Brands_Image_Path')->after('name')->nullable();
            $table->integer('Brands_Size')->after('Products_Brands_Image_Path')->nullable();
            $table->string('Brands_Extension', 10)->after('Products_Brands_Size')->nullable();
            $table->string('Brands_Type', 50)->after('Products_Brands_Extension')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Products_Brands_Master_T', function (Blueprint $table) {
            //
        });
    }
};
