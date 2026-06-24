<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Product_Reviews_T', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('Products_Id');
            $table->unsignedBigInteger('Customers_Id')->nullable();
            $table->unsignedBigInteger('Orders_Placed_Id')->nullable();
            $table->unsignedBigInteger('Orders_Placed_Details_Id')->nullable();
            $table->unsignedTinyInteger('Rating');
            $table->string('Title', 160)->nullable();
            $table->text('Body');
            $table->string('Status', 20)->default('pending');
            $table->boolean('Verified_Purchase')->default(false);
            $table->unsignedInteger('Helpful_Count')->default(0);
            $table->unsignedInteger('Report_Count')->default(0);
            $table->unsignedBigInteger('Moderated_By')->nullable();
            $table->timestamp('Moderated_At')->nullable();
            $table->string('Moderator_Note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['Products_Id', 'Status']);
            $table->index(['Customers_Id', 'Products_Id']);
            $table->index(['Status', 'Report_Count']);
        });

        Schema::create('Product_Review_Replies_T', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('Product_Review_Id');
            $table->string('Reply_Type', 20);
            $table->unsignedBigInteger('User_Id')->nullable();
            $table->unsignedBigInteger('Vendor_User_Id')->nullable();
            $table->unsignedBigInteger('Vendor_Id')->nullable();
            $table->text('Body');
            $table->string('Status', 20)->default('approved');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['Product_Review_Id', 'Status']);
            $table->index(['Vendor_Id']);
        });

        Schema::create('Product_Review_Votes_T', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('Product_Review_Id');
            $table->unsignedBigInteger('Customers_Id')->nullable();
            $table->string('Vote_Type', 20);
            $table->string('Reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['Product_Review_Id', 'Customers_Id', 'Vote_Type'], 'uq_review_customer_vote');
            $table->index(['Product_Review_Id', 'Vote_Type']);
        });

        Schema::create('Product_Questions_T', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('Products_Id');
            $table->unsignedBigInteger('Customers_Id')->nullable();
            $table->text('Question');
            $table->string('Status', 20)->default('pending');
            $table->unsignedInteger('Helpful_Count')->default(0);
            $table->unsignedInteger('Report_Count')->default(0);
            $table->unsignedBigInteger('Moderated_By')->nullable();
            $table->timestamp('Moderated_At')->nullable();
            $table->string('Moderator_Note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['Products_Id', 'Status']);
            $table->index(['Customers_Id', 'Products_Id']);
            $table->index(['Status', 'Report_Count']);
        });

        Schema::create('Product_Question_Answers_T', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('Product_Question_Id');
            $table->string('Answer_Type', 20);
            $table->unsignedBigInteger('User_Id')->nullable();
            $table->unsignedBigInteger('Vendor_User_Id')->nullable();
            $table->unsignedBigInteger('Vendor_Id')->nullable();
            $table->text('Body');
            $table->string('Status', 20)->default('approved');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['Product_Question_Id', 'Status']);
            $table->index(['Vendor_Id']);
        });

        Schema::create('Product_Question_Votes_T', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('Product_Question_Id');
            $table->unsignedBigInteger('Customers_Id')->nullable();
            $table->string('Vote_Type', 20);
            $table->string('Reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['Product_Question_Id', 'Customers_Id', 'Vote_Type'], 'uq_question_customer_vote');
            $table->index(['Product_Question_Id', 'Vote_Type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Product_Question_Votes_T');
        Schema::dropIfExists('Product_Question_Answers_T');
        Schema::dropIfExists('Product_Questions_T');
        Schema::dropIfExists('Product_Review_Votes_T');
        Schema::dropIfExists('Product_Review_Replies_T');
        Schema::dropIfExists('Product_Reviews_T');
    }
};
