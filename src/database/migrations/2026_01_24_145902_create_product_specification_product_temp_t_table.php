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
        Schema::create('Product_Specification_Product_Temp_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Product_Temporary_Id');

            // 🔹 Same as your final table
            $table->unsignedBigInteger('Product_Specification_Description_Id');

            // 🔹 Chosen value (dropdown) – nullable in case some specs are free-text or optional
            $table->unsignedBigInteger('product_specification_value_id')->nullable();

            // 🔹 Audit
            $table->unsignedBigInteger('Created_By')->nullable();

            $table->timestamps();

            // Indexes for performance
            $table->index('Product_Temporary_Id', 'idx_psp_temp_product');
            $table->index('Product_Specification_Description_Id', 'idx_psp_temp_desc');
            $table->index('product_specification_value_id', 'idx_psp_temp_value');

            // Optional: prevent duplicate spec rows per temp product
            $table->unique(
                ['Product_Temporary_Id', 'Product_Specification_Description_Id'],
                'uq_psp_temp_product_desc'
            );

            // 🔹 Foreign keys (optional but recommended – adjust table names if different)

            $table->foreign('Product_Temporary_Id')
                ->references('id')
                ->on('Products_Temporary_T')
                ->onDelete('no action');

            $table->foreign('Product_Specification_Description_Id')
                ->references('id')
                ->on('Product_Specification_Description_T')
                ->onDelete('no action');

            $table->foreign('product_specification_value_id')
                ->references('id')
                ->on('Product_Specification_Value_T')
                ->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Product_Specification_Product_Temp_T');
    }
};
