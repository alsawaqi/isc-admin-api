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
        Schema::table('Shipper_Contacts_T', function (Blueprint $table) {
            //

            $table->unsignedBigInteger('Contact_Department_Id')
                   ->nullable()
                   ->after('Shippers_Id');

            $table->foreign('Contact_Department_Id')
                   ->references('id')
                   ->on('Customers_Contact_T')
                   ->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::table('Shipper_Contacts_T', function (Blueprint $table) {
            //
        });
    }
};
