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
        Schema::table('Products_Departments_T', function (Blueprint $table) {
            $table->index('Product_Department_Name', 'idx_pd_search_name');
        });

        Schema::table('Products_Sub_Department_T', function (Blueprint $table) {
            $table->index('Products_Departments_Id', 'idx_psd_parent');
            $table->index(['Products_Departments_Id', 'Sub_Department_Name'], 'idx_psd_parent_name');
        });

        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            $table->index('Product_Sub_Department_Id', 'idx_pssd_parent');
            $table->index('Slug', 'idx_pssd_slug');
            $table->index(['Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Name'], 'idx_pssd_parent_name');
        });

        Schema::table('Products_Master_T', function (Blueprint $table) {
            $table->index('Slug', 'idx_pm_slug');
            $table->index('Product_Sku', 'idx_pm_sku');
            $table->index(['Product_Sub_Sub_Department_Id', 'Status'], 'idx_pm_subsub_status');
            $table->index(['Product_Department_Id', 'Product_Sub_Department_Id', 'Product_Sub_Sub_Department_Id'], 'idx_pm_category_tree');
            $table->index(['Product_Brand_Id', 'Product_Manufacture_Id'], 'idx_pm_brand_mfg');
        });

        Schema::table('Products_Images_T', function (Blueprint $table) {
            $table->index('Products_Id', 'idx_pi_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Products_Images_T', function (Blueprint $table) {
            $table->dropIndex('idx_pi_product');
        });

        Schema::table('Products_Master_T', function (Blueprint $table) {
            $table->dropIndex('idx_pm_brand_mfg');
            $table->dropIndex('idx_pm_category_tree');
            $table->dropIndex('idx_pm_subsub_status');
            $table->dropIndex('idx_pm_sku');
            $table->dropIndex('idx_pm_slug');
        });

        Schema::table('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            $table->dropIndex('idx_pssd_parent_name');
            $table->dropIndex('idx_pssd_slug');
            $table->dropIndex('idx_pssd_parent');
        });

        Schema::table('Products_Sub_Department_T', function (Blueprint $table) {
            $table->dropIndex('idx_psd_parent_name');
            $table->dropIndex('idx_psd_parent');
        });

        Schema::table('Products_Departments_T', function (Blueprint $table) {
            $table->dropIndex('idx_pd_search_name');
        });
    }
};
