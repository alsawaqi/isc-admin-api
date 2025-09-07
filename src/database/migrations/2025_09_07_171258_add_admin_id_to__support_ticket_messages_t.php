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
        Schema::table('Support_Ticket_Messages_T', function (Blueprint $table) {
            $table->unsignedBigInteger('Admin_Id')->nullable()->after('Message_Body');
            $table->foreign('Admin_Id')->references('id')->on('Secx_Admin_User_Master_T')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Support_Ticket_Messages_T', function (Blueprint $table) {
            //
        });
    }
};
