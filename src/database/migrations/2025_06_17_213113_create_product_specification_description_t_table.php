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
        Schema::create('Product_Specification_Description_T', function (Blueprint $table) {
    $table->id();
    $table->string('name'); 
    $table->foreignId('product_sub_sub_department_id')
          ->constrained('Products_Sub_Sub_Department_T')
          ->onDelete('cascade');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Product_Specification_Description_T');
    }
};
