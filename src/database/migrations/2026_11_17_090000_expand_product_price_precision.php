<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Products_Master_T', function (Blueprint $table) {
            $table->decimal('Product_Price', 18, 3)->change();
        });
    }

    public function down(): void
    {
        // Keep the expanded precision: narrowing it would round imported prices.
    }
};
