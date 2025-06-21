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
        Schema::create('Product_Specification_Product_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('Products_Master_T')
                ->onDelete('cascade');
            $table->foreignId('product_specification_description_id')
                ->constrained('product_specification_description_t')
                ->onDelete('cascade');
            $table->string('value'); // e.g., 'Large', 'Red'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Product_Specification_Product_T');
    }
};
