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
        Schema::table('Shippers_Master_T', function (Blueprint $table) {
            $table->boolean('Shippers_COD')->after('Shippers_Meta')->nullable();
          });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Shippers_Master_T', function (Blueprint $table) {
            //
        });
    }
};
