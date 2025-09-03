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
        Schema::create('Support_Tickets_T', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('Ticket_Reference', 40)->unique();
            $table->unsignedBigInteger('User_Id')->nullable();      // Maps to Secx_User_Master_T.User_Id (or fallback to users.id)
            $table->unsignedBigInteger('Customer_Id')->nullable();  // Maps to Customers_Master_T.id
            $table->string('Subject', 200);
            $table->string('Ticket_Type', 30);  // 'support' | 'feedback' | 'return'
            $table->unsignedBigInteger('Order_Id')->nullable();
            $table->string('Ticket_Status', 20)->default('open'); // 'open' | 'pending' | 'closed'
            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::dropIfExists('Support_Tickets_T');
    }
};
