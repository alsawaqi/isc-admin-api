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
        Schema::create('Secx_Vendors_Users_Master_T', function (Blueprint $table) {
            $table->id();

                  // ✅ link to vendor
      $table->unsignedBigInteger('Vendor_Id')->nullable();

      // ---- fields matching Secx_User_Master_T ----
      $table->string('User_Id', 30);                  // not null in your table
      $table->string('User_Name', 150)->nullable();
      $table->string('email', 255)->nullable();
      $table->dateTime('Email_verified_at')->nullable();

      $table->string('password', 255);               // not null in your table
      $table->string('remember_token', 100)->nullable();

      $table->string('Login_Password', 100)->nullable();

      $table->string('Merchant_Id', 12)->nullable();
      $table->string('Company_Code', 50)->nullable();

      $table->integer('Merchant_Jurisdiction_Code')->nullable();
      $table->integer('User_Type_Code')->nullable();
      $table->integer('Department_Code')->nullable();
      $table->integer('Role_Code')->nullable();
      $table->integer('Designation_Code')->nullable();

      $table->float('No_Login')->nullable();
      $table->float('Successful_Login')->nullable();

      $table->string('Status', 50)->nullable();
      $table->dateTime('Password_Changed_Date')->nullable();

      $table->string('Phone', 50)->nullable();
      $table->string('Gsm', 50)->nullable();
      $table->string('FAX', 50)->nullable();
      $table->string('Alternate_Email', 50)->nullable();
      $table->string('Postal_Code', 10)->nullable();
      $table->string('PO_Box', 10)->nullable();

      // These are codes in your Secx_User_Master_T
      $table->string('Region_Code', 5)->nullable();
      $table->string('Location_Code', 5)->nullable();
      $table->string('Country_Code', 12)->nullable();

      $table->boolean('Additional_Rights_Updated_Status')->nullable();
      $table->string('Created_User_Id', 12)->nullable();
      $table->boolean('Updated_Status', 1)->nullable();

      $table->timestamps();

      $table->text('Two_Factor_Secret')->nullable();
      $table->text('Two_Factor_Recovery_Codes')->nullable();
      $table->dateTime('Two_Factor_Confirmed_At')->nullable();

      // Indexes / constraints
      $table->unique('User_Id');
      $table->index(['email']);
      $table->index(['Vendor_Id']);

      $table->foreign('Vendor_Id')->references('id')->on('Vendors_Master_T')->nullOnDelete();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Secx_Vendors_Users_Master_T');
    }
};
