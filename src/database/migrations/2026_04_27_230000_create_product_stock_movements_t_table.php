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
        Schema::create('Product_Stock_Movements_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Products_Id');
            $table->unsignedBigInteger('Vendor_Id')->nullable();
            $table->string('Movement_Type', 30);
            $table->integer('Quantity_Delta');
            $table->integer('Quantity');
            $table->integer('Previous_Stock');
            $table->integer('New_Stock');
            $table->string('Actor_Type', 30)->nullable();
            $table->unsignedBigInteger('Actor_Id')->nullable();
            $table->string('Actor_Name')->nullable();
            $table->text('Notes')->nullable();
            $table->timestamps();

            $table->foreign('Products_Id')
                ->references('id')
                ->on('Products_Master_T')
                ->onDelete('no action');

            $table->index('Products_Id', 'idx_product_stock_movements_product');
            $table->index('Vendor_Id', 'idx_product_stock_movements_vendor');
            $table->index('Movement_Type', 'idx_product_stock_movements_type');
            $table->index(['Products_Id', 'created_at'], 'idx_product_stock_movements_product_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Product_Stock_Movements_T');
    }
};
