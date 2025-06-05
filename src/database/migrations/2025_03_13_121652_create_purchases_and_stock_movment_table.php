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
         // Suppliers Table
         Schema::create('Procurement_Suppliers_T', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code')->unique()->nullable();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('phone')->unique();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        // Purchases Table
        Schema::create('Procurement_Purchases_T', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_code')->unique()->nullable();
            $table->foreignId('supplier_id')->constrained('Procurement_Suppliers_T')->onDelete('no action');
            $table->foreignId('product_id')->constrained('products_master_t')->onDelete('no action');
            $table->integer('quantity');
            $table->decimal('purchase_price', 10, 2);
            $table->date('purchase_date');
            $table->timestamps();
        });

        // Stock Movements Table
        Schema::create('Procurement_Stock_Movements_T', function (Blueprint $table) {
            $table->id();
            $table->string('movement_code')->unique()->nullable();
            $table->foreignId('product_id')->constrained('products_master_t')->onDelete('no action');
            $table->enum('movement_type', ['purchase', 'sale', 'return', 'adjustment']);
            $table->integer('quantity');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases_and_stock_movment');
    }
};
