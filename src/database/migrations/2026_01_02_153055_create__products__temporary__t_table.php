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
        Schema::create('Products_Temporary_T', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('Vendor_Id');
            $table->string('Temp_Product_Code', 50)->unique(); // e.g. TP-000001
      
            // Core product fields (add more to match your master table later)
            $table->string('Product_Name', 255);
            $table->string('Product_Name_Ar', 255)->nullable();
      
            $table->text('Description')->nullable();
            $table->text('Description_Ar')->nullable();
      
            // Category chain (same ids as your real tables)
            $table->bigInteger('Product_Department_Id')->nullable();
            $table->bigInteger('Product_Sub_Department_Id')->nullable();
            $table->bigInteger('Product_Sub_Sub_Department_Id')->nullable();
      
            $table->bigInteger('Product_Brand_Id')->nullable();
            $table->bigInteger('Product_Manufacture_Id')->nullable();
            $table->bigInteger('Product_Type_Id')->nullable();
      
            // Pricing & stock
            $table->decimal('Product_Price', 18, 3)->nullable();
            $table->decimal('Product_Cost', 18, 3)->nullable();
            $table->decimal('Product_Stock', 18, 3)->nullable();
      
            // Shipping dimensions
            $table->decimal('Weight_Kg', 18, 3)->nullable();
            $table->decimal('Length_Cm', 18, 3)->nullable();
            $table->decimal('Width_Cm', 18, 3)->nullable();
            $table->decimal('Height_Cm', 18, 3)->nullable();
            $table->decimal('Volume_Cbm', 18, 6)->nullable();
      
            // Workflow
            $table->string('Submission_Status', 30)->default('draft');
            // draft | submitted | approved | rejected
      
            $table->bigInteger('Submitted_By')->nullable(); // vendor user id
            $table->timestamp('Submitted_At')->nullable();
      
            $table->bigInteger('Reviewed_By')->nullable(); // admin user id
            $table->timestamp('Reviewed_At')->nullable();
            $table->string('Rejection_Reason', 500)->nullable();
      
            $table->bigInteger('Approved_Product_Id')->nullable(); 
            // when approved, link to Products_Master_T.Id
      
            // Auditing
            $table->bigInteger('Created_By')->nullable();
            $table->bigInteger('Updated_By')->nullable();
      
            $table->timestamps();
            $table->softDeletes();
      
            $table->foreign('Vendor_Id')
              ->references('Id')->on('Vendors_Master_T')
              ->onDelete('cascade');
      
            $table->index(['Vendor_Id']);
            $table->index(['Submission_Status']);
            $table->index(['Submitted_At']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Products_Temporary_T');
    }
};
