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
        Schema::table('Orders_Placed_Vendors_T', function (Blueprint $table) {
              // store final paid amount (recommended)
              $table->decimal('Payout_Amount', 18, 3)->nullable()->after('Payout_Status');

              // date/time of payout
              $table->dateTime('Payout_At')->nullable()->after('Payout_Amount');
  
              // optional: reference / note for auditing
              $table->string('Payout_Reference', 100)->nullable()->after('Payout_At');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Orders_Placed_Vendors_T', function (Blueprint $table) {
            //
        });
    }
};
