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
        //
          Schema::table('Order_Process_Log_T', function (Blueprint $table) {
            $table->string('Signature_Storage', 255)->nullable()->change();
            $table->string('Signature_Url', 1024)->nullable()->change();
            $table->string('Signature_Mime', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //

         Schema::table('Order_Process_Log_T', function (Blueprint $table) {
            $table->string('Signature_Storage', 50)->nullable()->change();
            $table->string('Signature_Url', 255)->nullable()->change();
            $table->string('Signature_Mime', 20)->nullable()->change();
        });
    }
};
