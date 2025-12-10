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
        Schema::create('Orders_Placed_Details_Cancelled_T', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('Orders_Placed_Details_Id');
            $table->foreign('Orders_Placed_Details_Id')->references('id')->on('Orders_Placed_Details_T')->onDelete('cascade');

            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->foreign('Orders_Placed_Id')->references('id')->on('Orders_Placed_T')->onDelete('cascade');

            $table->unsignedBigInteger('Cancelled_By_Users_Id');
            $table->foreign('Cancelled_By_Users_Id')->references('id')->on('Secx_Admin_User_Master_T')->onDelete('cascade');

         
            $table->text('Cancellation_Reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Orders_Placed_Details_Cancelled_T');
    }
};
