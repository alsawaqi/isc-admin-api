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
         Schema::table('Product_Specification_Product_T', function (Blueprint $table) {
            // Keep your current value column(s) if you have them; add FK for normalized value usage
            $table->unsignedBigInteger('product_specification_value_id')->nullable()->after('Product_Specification_Description_Id');

            $table->foreign('product_specification_value_id', 'psp_value_fk')
                ->references('id')
                ->on('Product_Specification_Value_T')
                ->onDelete('no action');

            // Ensure no duplicate spec row per product (either existing unique or add this):
             $table->unique(['product_id','Product_Specification_Description_Id'], 'psp_unique_prod_desc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Product_Specification_Product_T', function (Blueprint $table) {
            $table->dropForeign('psp_value_fk');
            $table->dropColumn('product_specification_value_id');
        });
    }
};
