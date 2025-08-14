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
       Schema::table('Product_Specification_Product_T', function (Blueprint $table) {
            if (Schema::hasColumn('Product_Specification_Product_T', 'value')) {
                $table->dropColumn('value');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::table('Product_Specification_Product_T', function (Blueprint $table) {
            // Add the column back if needed
            $table->string('value', 255)->nullable();
        });
    }
};
