<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Product_Import_Jobs_T', function (Blueprint $table) {
            $table->id();
            $table->uuid('Token')->unique();
            $table->unsignedBigInteger('User_Id')->index();
            $table->string('File_Name');
            $table->string('File_Sha256', 64)->index();
            $table->string('Status', 20)->default('pending');
            $table->longText('Payload');
            $table->string('Plan_Digest', 64);
            $table->longText('Result')->nullable();
            $table->dateTime('Expires_At');
            $table->dateTime('Committed_At')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Product_Import_Jobs_T');
    }
};
