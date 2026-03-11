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
        Schema::create('Product_Specification_Product_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('Product_Id')
                  ->constrained('Products_Master_T')
                  ->onDelete('cascade');
            $table->foreignId('Product_Specification_Description_Id')
                ->constrained('Product_Specification_Description_T')
                ->onDelete('cascade');
            $table->string('value'); // e.g., 'Large', 'Red'
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
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
        Schema::dropIfExists('Product_Specification_Product_T');
    }
};
