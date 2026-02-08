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
        Schema::create('Products_Temporary_Images_T', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('Products_Temporary_Id');

            $table->string('Image_Path', 500);
            $table->bigInteger('Image_Size')->nullable();
            $table->string('Image_Extension', 20)->nullable();
            $table->string('Image_Type', 100)->nullable(); // mime type
      
            $table->boolean('Is_Default')->default(false);
      
            $table->bigInteger('Created_By')->nullable();
            $table->timestamps();
            $table->softDeletes();
      
            $table->foreign('Products_Temporary_Id')
              ->references('Id')->on('Products_Temporary_T')
              ->onDelete('cascade');
      
            $table->index(['Products_Temporary_Id']);
            $table->index(['Is_Default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Products_Temporary_Images_T');
    }
};
