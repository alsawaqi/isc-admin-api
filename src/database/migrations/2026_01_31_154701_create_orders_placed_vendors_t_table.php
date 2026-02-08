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
        Schema::create('Orders_Placed_Vendors_T', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('Orders_Placed_Id');   // FK -> Orders_Placed_T.id
            $table->unsignedBigInteger('Vendor_Id')->nullable(); // null = ISC/admin products (optional)

            $table->string('Vendor_Order_Code', 50)->unique(); // e.g. VORD-xxxxx

            $table->decimal('Sub_Total', 18, 3)->default(0);
            $table->decimal('VAT', 18, 3)->default(0);
            $table->decimal('Shipping', 18, 3)->default(0);
            $table->decimal('Total', 18, 3)->default(0);

            $table->string('Status', 20)->default('pending'); // pending|processing|shipped|completed|cancelled

            $table->string('Commission_Type', 10)->nullable();  // percent|fixed
            $table->decimal('Commission_Value', 18, 3)->nullable(); // percent value or fixed value
            $table->decimal('Commission_Amount', 18, 3)->nullable(); // stored calculated commission

            $table->string('Payout_Status', 20)->default('unpaid'); // unpaid|requested|paid

            $table->timestamps();

            // Indexes
            $table->index(['Orders_Placed_Id']);
            $table->index(['Vendor_Id']);
            $table->index(['Orders_Placed_Id', 'Vendor_Id']);

            // Foreign keys
            $table->foreign('Orders_Placed_Id')
                ->references('id')
                ->on('Orders_Placed_T')
                ->onDelete('cascade');

            // If you have a Vendors table, add it. Otherwise keep it as index only.
            $table->foreign('Vendor_Id')
                ->references('id')
                ->on('Vendors_Master_T')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_placed_vendors_t');
    }
};
