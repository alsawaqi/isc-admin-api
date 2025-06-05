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

        Schema::create('Secx_User_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('User_Id', 30);
            $table->string('User_Name', 150)->nullable();
            $table->string('email')->unique()->nullable(); // Laravel default
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password'); // Laravel default
            $table->rememberToken()->nullable();
            $table->string('Login_Password', 100)->nullable(); // Renamed from Password
            $table->string('Merchant_Id', 12)->nullable();
            $table->string('Company_Code', 50)->nullable();
            $table->integer('Merchant_Jurisdiction_Code')->nullable();
            $table->integer('User_Type_Code')->nullable();
            $table->integer('Department_Code')->nullable();
            $table->integer('Role_Code')->nullable();
            $table->integer('Designation_Code')->nullable();
            $table->float('No_Login')->nullable();
            $table->float('Successful_Login')->nullable();
            $table->boolean('Status')->nullable();
            $table->dateTime('Password_Changed_Date')->nullable();
            $table->string('Phone', 50)->nullable();
            $table->string('Gsm', 50)->nullable();
            $table->string('FAX', 50)->nullable();
            $table->string('Alternate_Email', 50)->nullable(); // Renamed from Email
            $table->string('Postal_Code', 10)->nullable();
            $table->string('PO_Box', 10)->nullable();
            $table->string('Region_Code', 5)->nullable();
            $table->string('Location_Code', 5)->nullable();
            $table->string('Country_Code', 12)->nullable();
            $table->boolean('Additional_Rights_Updated_Status')->nullable();
            $table->string('Created_User_Id', 12)->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();

        });


        Schema::create('Secx_Admin_User_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('User_Id', 30);
            $table->string('User_Name', 150)->nullable();
            $table->string('email')->unique(); // Laravel default
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable(); // Laravel default
            $table->rememberToken()->nullable();
            $table->string('Login_Password', 100)->nullable(); // Renamed from Password
            $table->string('Merchant_Id', 12)->nullable();
            $table->string('Company_Code', 50)->nullable();
            $table->integer('Merchant_Jurisdiction_Code')->nullable();
            $table->integer('User_Type_Code')->nullable();
            $table->integer('Department_Code')->nullable();
            $table->integer('Role_Code')->nullable();
            $table->integer('Designation_Code')->nullable();
            $table->float('No_Login')->nullable();
            $table->float('Successful_Login')->nullable();
            $table->boolean('Status')->nullable();
            $table->dateTime('Password_Changed_Date')->nullable();
            $table->string('Phone', 50)->nullable();
            $table->string('Gsm', 50)->nullable();
            $table->string('FAX', 50)->nullable();
            $table->string('Alternate_Email', 50)->nullable(); // Renamed from Email
            $table->string('Postal_Code', 10)->nullable();
            $table->string('PO_Box', 10)->nullable();
            $table->string('Region_Code', 5)->nullable();
            $table->string('Location_Code', 5)->nullable();
            $table->string('Country_Code', 12)->nullable();
            $table->boolean('Additional_Rights_Updated_Status')->nullable();
            $table->string('Created_User_Id', 12)->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();

        });

        Schema::create('Security_Password_Reset_Tokens_T', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('Conx_Sessions_T', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained('Secx_User_Master_T')->onDelete('no action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
