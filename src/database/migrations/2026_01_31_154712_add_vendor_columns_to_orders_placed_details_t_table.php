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
        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            // Add columns
        
            $table->unsignedBigInteger('Orders_Placed_Vendor_Id')->nullable()->after('Vendor_Id');

            // Indexes
          
            $table->index('Orders_Placed_Vendor_Id', 'idx_opd_vendor_order_id');
            $table->index(['Orders_Placed_Id', 'Vendor_Id'], 'idx_opd_order_vendor');

            // FK -> Orders_Placed_Vendors_T
            $table->foreign('Orders_Placed_Vendor_Id', 'fk_opd_vendor_order')
                ->references('id')
                ->on('Orders_Placed_Vendors_T')
                ->nullOnDelete();

             
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            //
        });
    }
};
