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
        Schema::table('Shippers_Master_T', function (Blueprint $table) {
             $table->string('Shippers_Image_Path')->after('Shippers_Meta')->nullable();
            $table->integer('Shippers_Size')->after('Shippers_Image_Path')->nullable();
            $table->string('Shippers_Extension', 10)->after('Shippers_Size')->nullable();
            $table->string('Shippers_Image_Type', 50)->after('Shippers_Extension')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Shippers_Master_T', function (Blueprint $table) {
            //
        });
    }
};
