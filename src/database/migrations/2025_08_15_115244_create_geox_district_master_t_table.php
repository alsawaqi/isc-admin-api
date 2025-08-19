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
        Schema::create('Geox_District_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('District_Code', 30)->unique();
            $table->string('District_Name');
            $table->string('District_Name_Ar')->nullable();
            $table->foreignId('Created_By', 12)
                   ->constrained('Secx_Admin_User_Master_T')
                   ->onDelete('no action')
                   ->nullable();

            $table->foreignId('Region_Id')
                   ->constrained('Geox_Region_Master_T')
                   ->onDelete('no action')
                   ->nullable();
      
           
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Geox_District_Master_T');
    }
};
