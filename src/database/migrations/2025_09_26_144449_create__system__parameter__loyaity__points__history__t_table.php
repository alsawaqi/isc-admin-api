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
        Schema::create('System_Parameter_Loyalty_Points_History_T', function (Blueprint $table) {
            $table->id();
            $table->decimal('Current_Point', 10, 3)->nullable();
            $table->decimal('Previous_Point', 10, 3)->nullable();
            $table->foreignId('Created_By')->constrained('Secx_Admin_User_Master_T')->onDelete('no action');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('System_Parameter_Loyalty_Points_History_T');
    }
};
