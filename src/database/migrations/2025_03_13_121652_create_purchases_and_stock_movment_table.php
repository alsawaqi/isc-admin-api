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
            $table->string('Supplier_Code')->unique()->nullable();
            $table->string('Name');
            $table->string('Email')->unique()->nullable();
            $table->string('Phone')->unique();
            $table->text('Address')->nullable();
            $table->timestamps();
        });

        // Purchases Table
        Schema::create('Procurement_Purchases_T', function (Blueprint $table) {
            $table->id();
            $table->string('Purchase_Code')->unique()->nullable();
            $table->foreignId('Supplier_Id')->constrained('Procurement_Suppliers_T')->onDelete('no action');
            $table->foreignId('Product_Id')->constrained('products_master_t')->onDelete('no action');
            $table->integer('Quantity');
            $table->decimal('Purchase_Price', 10, 2);
            $table->date('Purchase_Date');
            $table->timestamps();
        });

        // Stock Movements Table
        Schema::create('Procurement_Stock_Movements_T', function (Blueprint $table) {
            $table->id();
            $table->string('Movement_Code')->unique()->nullable();
            $table->foreignId('Product_Id')->constrained('products_master_t')->onDelete('no action');
            $table->enum('Movement_Type', ['purchase', 'sale', 'return', 'adjustment']);
            $table->integer('Quantity');
            $table->text('Remarks')->nullable();
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
