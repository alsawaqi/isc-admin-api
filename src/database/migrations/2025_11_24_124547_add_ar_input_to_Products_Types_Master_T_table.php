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
        Schema::table('Products_Types_Master_T', function (Blueprint $table) {

            $table->string('Product_Types_Name_Ar')->nullable()->after('Product_Types_Name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
