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
        Schema::create('Product_Specification_Value_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_specification_description_id');
            $table->string('value', 255);              // Display text (e.g., "Green")
            $table->string('normalized_value', 255)->nullable(); // optional canonical (e.g., "green")
            $table->string('slug', 255)->nullable();   // optional for URLs
            $table->timestamps();

            $table->foreign('product_specification_description_id', 'psv_desc_fk')
                ->references('id')
                ->on('Product_Specification_Description_T')
                ->onDelete('cascade');

            // Ensure a value is unique within a given spec description
            $table->unique(
                ['product_specification_description_id', 'value'],
                'psv_unique_desc_value'
            );

            $table->index('product_specification_description_id', 'psv_desc_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Product_Specification_Value_T');
    }
};
