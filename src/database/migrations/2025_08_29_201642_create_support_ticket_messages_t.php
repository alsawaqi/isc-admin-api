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
        Schema::create('Support_Ticket_Messages_T', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('Ticket_Id');           // FK -> Support_Tickets_T.id
            $table->string('Sender_Type', 20);                 // 'user' | 'support'
            $table->text('Message_Body');
            $table->timestamps();

            $table->foreign('Ticket_Id')
                  ->references('id')->on('Support_Tickets_T')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Support_Ticket_Messages_T');
    }
};
