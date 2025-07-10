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
        Schema::table('Products_Manufacture_Master_T', function (Blueprint $table) {
              $table->string('Department')
                    ->after('Product_Manufacture_Code')
                    ->default('undefined');
         });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Products_Manufacture_Master_T', function (Blueprint $table) {
            
        });
    }
};
