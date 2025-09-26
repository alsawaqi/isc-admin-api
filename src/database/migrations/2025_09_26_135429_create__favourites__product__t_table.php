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
        Schema::create('Favorites_Master_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
            $table->foreignId('Customers_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Favorites_Master_T');
    }
};
