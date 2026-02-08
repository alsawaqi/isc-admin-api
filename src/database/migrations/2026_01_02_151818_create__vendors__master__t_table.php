<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('Vendors_Master_T', function (Blueprint $table) {
        $table->id();


        $table->string('Vendor_Code', 50)->unique(); // VEN-000001
        $table->string('Vendor_Name', 255);
        $table->string('Trade_Name', 255)->nullable();

        $table->string('CR_Number', 100)->nullable();
        $table->string('VAT_Number', 100)->nullable();

        $table->string('Email_1', 255)->nullable();
        $table->string('Phone_No', 50)->nullable();

        // Address text lines
        $table->string('Address_Line1', 255)->nullable();
        $table->string('Address_Line2', 255)->nullable();
        $table->string('Postal_Code', 30)->nullable();
        $table->string('PO_Box', 30)->nullable();

        // ✅ Geo links (based on your isc.sql tables)
        $table->unsignedBigInteger('Country_Id')->nullable();
        $table->unsignedBigInteger('Region_Id')->nullable();
        $table->unsignedBigInteger('District_Id')->nullable();
        $table->unsignedBigInteger('City_Id')->nullable();

        $table->string('Status', 30)->default('active'); // active|pending|suspended|blocked
        $table->boolean('Is_Active')->default(true);

        $table->bigInteger('Created_By')->nullable();
        $table->bigInteger('Updated_By')->nullable();

        $table->timestamps();
        $table->softDeletes();

        $table->index(['Status']);
        $table->index(['Is_Active']);
        $table->index(['CR_Number']);
        $table->index(['Email_1']);
        $table->index(['Country_Id', 'Region_Id', 'District_Id', 'City_Id']);

        // FKs
        $table->foreign('Country_Id')->references('id')->on('Geox_Country_Master_T');
        $table->foreign('Region_Id')->references('id')->on('Geox_Region_Master_T');
        $table->foreign('District_Id')->references('id')->on('Geox_District_Master_T');
        $table->foreign('City_Id')->references('id')->on('Geox_City_Master_T');


    });
  }

  public function down(): void
  {
    Schema::dropIfExists('Vendors_Master_T');
  }
};
