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
            $table->index(
                ['Vendor_Id', 'Product_Department_Id', 'Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Id'],
                'idx_pm_vendor_category'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Products_Master_T', function (Blueprint $table) {
            $table->dropIndex('idx_pm_vendor_category');
        });
    }
};
