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
        Schema::create('Products_Vendor_Requests_T', function (Blueprint $table) {
            $table->id();
                        // Relations
                        $table->unsignedBigInteger('Products_Temporary_Id'); // from Products_Temporary_T
                        $table->unsignedBigInteger('Products_Id')->nullable(); // from Products_Master_T (after approval)
                        $table->unsignedBigInteger('Vendor_Id'); // vendor who owns this product
            
                        // Status + comment (timeline info)
                        $table->string('Status', 30); // 'pending', 'changes_requested', 'approved', 'rejected', 'cancelled'
                        $table->text('Comment')->nullable();
            
                        // Who performed this step
                        $table->unsignedBigInteger('Action_By_User_Id')->nullable(); // admin or vendor user id
                        $table->string('Action_By_Role', 20)->nullable(); // 'admin' or 'vendor'
            
                        // When this step happened
                        $table->dateTime('Action_At')->useCurrent();
            
                        // Laravel timestamps (optional)
                        $table->timestamps();
            
                        // Indexes for fast lookup
                        $table->index('Products_Temporary_Id');
                        $table->index('Products_Id');
                        $table->index('Vendor_Id');
                        $table->index('Status');
          
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Products_Vendor_Requests_T');
    }
};
