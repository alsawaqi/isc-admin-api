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
            $table->string('image_path')->after('name')->nullable();
            $table->integer('size')->after('image_path')->nullable();
            $table->string('extension', 10)->after('size')->nullable();
            $table->string('type', 50)->after('extension')->nullable();
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
