<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Orders_Placed_Details_Adjustments_T')) {
            return;
        }

        Schema::create('Orders_Placed_Details_Adjustments_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->unsignedBigInteger('Orders_Placed_Details_Id');
            $table->unsignedBigInteger('Orders_Placed_Vendor_Id')->nullable();
            $table->unsignedBigInteger('Products_Id')->nullable();
            $table->unsignedBigInteger('Vendor_Id')->nullable();
            $table->string('Adjustment_Type', 40);
            $table->integer('Quantity')->default(0);
            $table->decimal('Amount', 18, 3)->default(0);
            $table->integer('Restock_Quantity')->default(0);
            $table->text('Reason')->nullable();
            $table->unsignedBigInteger('Actor_User_Id')->nullable();
            $table->string('Actor_Name', 150)->nullable();
            $table->text('Signature_Url')->nullable();
            $table->string('Signature_Mime', 50)->nullable();
            $table->json('Metadata')->nullable();
            $table->timestamps();

            $table->foreign('Orders_Placed_Id', 'fk_opda_order')
                ->references('id')
                ->on('Orders_Placed_T')
                ->onUpdate('no action')
                ->onDelete('no action');

            $table->foreign('Orders_Placed_Details_Id', 'fk_opda_order_detail')
                ->references('id')
                ->on('Orders_Placed_Details_T')
                ->onUpdate('no action')
                ->onDelete('no action');

            $table->foreign('Orders_Placed_Vendor_Id', 'fk_opda_vendor_order')
                ->references('id')
                ->on('Orders_Placed_Vendors_T')
                ->onUpdate('no action')
                ->nullOnDelete();

            $table->foreign('Products_Id', 'fk_opda_product')
                ->references('id')
                ->on('Products_Master_T')
                ->onUpdate('no action')
                ->nullOnDelete();

            $table->foreign('Vendor_Id', 'fk_opda_vendor')
                ->references('id')
                ->on('Vendors_Master_T')
                ->onUpdate('no action')
                ->nullOnDelete();

            $table->foreign('Actor_User_Id', 'fk_opda_actor_user')
                ->references('id')
                ->on('Secx_Admin_User_Master_T')
                ->onUpdate('no action')
                ->nullOnDelete();

            $table->index(['Orders_Placed_Id', 'created_at'], 'idx_opda_order_date');
            $table->index(['Orders_Placed_Details_Id', 'created_at'], 'idx_opda_detail_date');
            $table->index('Adjustment_Type', 'idx_opda_type');
            $table->index('Orders_Placed_Vendor_Id', 'idx_opda_vendor_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Orders_Placed_Details_Adjustments_T');
    }
};
