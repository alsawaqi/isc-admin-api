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
        Schema::table('Products_Master_T', function (Blueprint $table) {
             $table->string('volume_type')
                    ->after('Weight_Kg')
                    ->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
          Schema::table('Products_Master_T', function (Blueprint $table) {
            $table->dropColumn('volume_type');
        });
    }
};
