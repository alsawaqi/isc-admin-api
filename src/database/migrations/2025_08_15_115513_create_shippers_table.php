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
    // 1) Shippers_Master_T
    Schema::create('Shippers_Master_T', function (Blueprint $table) {
        $table->id(); // PK = id
        $table->string('Shippers_Code')->unique();
        $table->string('Shippers_Name');
        $table->string('Shippers_Address')->nullable();
        $table->string('Shippers_Office_No')->nullable();
        $table->string('Shippers_GSM_No')->nullable();
        $table->string('Shippers_Email_Address')->nullable();
        $table->string('Shippers_Official_Website_Address')->nullable();
        $table->string('Shippers_GPS_Location')->nullable();
        $table->string('Shippers_Scope', 20)->index();      // local | international
        $table->string('Shippers_Type', 30)->index();       // parcel | courier | postal | heavy | ...
        $table->string('Shippers_Rate_Mode', 15)->index();  // weight | volume | both
        $table->boolean('Shippers_Is_Active')->default(true);
        $table->json('Shippers_Meta')->nullable();
        $table->timestamps();
    });

    // 2) Shipper_Contacts_T
    Schema::create('Shipper_Contacts_T', function (Blueprint $table) {
        $table->id(); // PK = id
        $table->unsignedBigInteger('Shippers_Id'); // FK → Shippers_Master_T.id
        $table->string('Shippers_Contact_Name');
        $table->string('Shippers_Contact_Position')->nullable();
        $table->string('Shippers_Contact_Office_No')->nullable();
        $table->string('Shippers_Contact_GSM_No')->nullable();
        $table->string('Shippers_Contact_Email_Address')->nullable();
        $table->boolean('Shippers_Is_Primary')->default(false);
        $table->timestamps();

        $table->foreign('Shippers_Id')
            ->references('id')->on('Shippers_Master_T')
            ->onDelete('no action');   // MSSQL NO ACTION
    });

    // 3) Shipper_Destinations_T
    Schema::create('Shipper_Destinations_T', function (Blueprint $table) {
        $table->id(); // PK = id
        $table->unsignedBigInteger('Shippers_Id'); // FK → Shippers_Master_T.id
        $table->string('Shippers_Destination_Country')->nullable();
        $table->string('Shippers_Destination_Region')->nullable();
        $table->string('Shippers_Destination_District')->nullable();
        $table->string('Shippers_Destination_Rate_Applicability')->nullable();
        $table->string('Shippers_Destination_Country_Preference')->nullable();
        $table->string('Shippers_Destination_Region_Preference')->nullable();
        $table->string('Shippers_Destination_District_Preference')->nullable();
        $table->timestamps();

        $table->foreign('Shippers_Id')
            ->references('id')->on('Shippers_Master_T')
            ->onDelete('no action');   // MSSQL NO ACTION

        $table->unique(
            ['Shippers_Id','Shippers_Destination_Country','Shippers_Destination_Region','Shippers_Destination_District'],
            'uniq_shipper_destination_label'
        );
    });

    // 4) Shipper_Shipping_Rates_T (with optional FKs to Geox tables)
    Schema::create('Shipper_Shipping_Rates_T', function (Blueprint $table) {
        $table->id(); // PK = id
        $table->unsignedBigInteger('Shippers_Id'); // FK → Shippers_Master_T.id
        $table->unsignedBigInteger('Shippers_Destination_Id'); // FK → Shipper_Destinations_T.id

        // Optional normalized links (nullable)
        $table->unsignedBigInteger('Shippers_Destination_Country_Id')->nullable();  // → Geox_Country_Master_T.Country_Id
        $table->unsignedBigInteger('Shippers_Destination_Region_Id')->nullable();   // → Geox_Region_Master_T.Region_Id
        $table->unsignedBigInteger('Shippers_Destination_District_Id')->nullable(); // → Geox_District_Master_T.District_Id

        $table->boolean('Shippers_Destination_Rate_Volume')->default(false);
        $table->boolean('Shippers_Destination_Rate_Weight')->default(false);
        $table->boolean('Shippers_Destination_Rate_Applicable')->default(true);
        $table->timestamps();

        $table->index(['Shippers_Id','Shippers_Destination_Id'], 'idx_shipper_shipping_rates_pair');

        $table->foreign('Shippers_Id')
            ->references('id')->on('Shippers_Master_T')
            ->onDelete('no action');   // MSSQL NO ACTION

        $table->foreign('Shippers_Destination_Id')
            ->references('id')->on('Shipper_Destinations_T')
            ->onDelete('no action');   // MSSQL NO ACTION

        // Optional FKs with NO ACTION (nullable)
        $table->foreign('Shippers_Destination_Country_Id')
            ->references('id')->on('Geox_Country_Master_T')
            ->onDelete('no action');

        $table->foreign('Shippers_Destination_Region_Id')
            ->references('id')->on('Geox_Region_Master_T')
            ->onDelete('no action');

        $table->foreign('Shippers_Destination_District_Id')
            ->references('id')->on('Geox_District_Master_T')
            ->onDelete('no action');
    });

    // 5) Shipper_Volume_Rates_T
    Schema::create('Shipper_Volume_Rates_T', function (Blueprint $table) {
        $table->id(); // PK = id
        $table->unsignedBigInteger('Shippers_Id');           // FK → Shippers_Master_T.id
        $table->unsignedBigInteger('Shippers_Destination_Id'); // FK → Shipper_Destinations_T.id
        $table->string('Shippers_Standard_Shipping_Volume_Size')->nullable(); // code/bucket
        $table->decimal('Shippers_Standard_Shipping_Volume_Rate', 12, 3)->default(0);
        $table->string('Shippers_Currency', 3)->default('OMR');
        $table->decimal('Shippers_Min_Volume_Cbm', 10, 4)->nullable();
        $table->decimal('Shippers_Max_Volume_Cbm', 10, 4)->nullable();
        $table->decimal('Shippers_Base_Fee', 12, 3)->nullable();
        $table->decimal('Shippers_Per_Cbm_Fee', 12, 3)->nullable();
        $table->decimal('Shippers_Flat_Fee', 12, 3)->nullable();
        $table->timestamps();

        $table->index(['Shippers_Id','Shippers_Destination_Id'], 'idx_volume_shipper_dest');

        $table->foreign('Shippers_Id')
            ->references('id')->on('Shippers_Master_T')
            ->onDelete('no action');   // MSSQL NO ACTION

        $table->foreign('Shippers_Destination_Id')
            ->references('id')->on('Shipper_Destinations_T')
            ->onDelete('no action');   // MSSQL NO ACTION
    });

    // 6) Shipper_Weight_Rates_T
    Schema::create('Shipper_Weight_Rates_T', function (Blueprint $table) {
        $table->id(); // PK = id
        $table->unsignedBigInteger('Shippers_Id');             // FK → Shippers_Master_T.id
        $table->unsignedBigInteger('Shippers_Destination_Id'); // FK → Shipper_Destinations_T.id
        $table->string('Shippers_Standard_Shipping_Weight_Size')->nullable(); // code/bucket
        $table->decimal('Shippers_Standard_Shipping_Weight_Rate', 12, 3)->default(0);
        $table->string('Shippers_Currency', 3)->default('OMR');
        $table->decimal('Shippers_Min_Weight_Kg', 10, 3)->nullable();
        $table->decimal('Shippers_Max_Weight_Kg', 10, 3)->nullable();
        $table->decimal('Shippers_Base_Fee', 12, 3)->nullable();
        $table->decimal('Shippers_Per_Kg_Fee', 12, 3)->nullable();
        $table->decimal('Shippers_Flat_Fee', 12, 3)->nullable();
        $table->timestamps();

        $table->index(['Shippers_Id','Shippers_Destination_Id'], 'idx_weight_shipper_dest');

        $table->foreign('Shippers_Id')
            ->references('id')->on('Shippers_Master_T')
            ->onDelete('no action');   // MSSQL NO ACTION

        $table->foreign('Shippers_Destination_Id')
            ->references('id')->on('Shipper_Destinations_T')
            ->onDelete('no action');   // MSSQL NO ACTION
    });

    // 7) Heavy_Vehicles_T
    Schema::create('Heavy_Vehicles_T', function (Blueprint $table) {
        $table->id(); // PK = id
        $table->unsignedBigInteger('Shippers_Id'); // FK → Shippers_Master_T.id
        $table->string('Shippers_Vehicle_Type');
        $table->decimal('Shippers_Vehicle_Capacity_Ton', 8, 2)->nullable();
        $table->timestamps();

        $table->foreign('Shippers_Id')
            ->references('id')->on('Shippers_Master_T')
            ->onDelete('no action');   // MSSQL NO ACTION
    });

    // 8) Shipper_Heavy_Rates_T
    Schema::create('Shipper_Heavy_Rates_T', function (Blueprint $table) {
        $table->id(); // PK = id
        $table->unsignedBigInteger('Shippers_Id');             // FK → Shippers_Master_T.id
        $table->unsignedBigInteger('Shippers_Destination_Id'); // FK → Shipper_Destinations_T.id
        $table->unsignedBigInteger('Shippers_Vehicle_Id');     // FK → Heavy_Vehicles_T.id
        $table->decimal('Shippers_Flat_Rate', 12, 3)->nullable();
        $table->decimal('Shippers_Hourly_Rate', 12, 3)->nullable();
        $table->unsignedInteger('Shippers_Min_Hours')->default(0);
        $table->string('Shippers_Currency', 3)->default('OMR');
        $table->timestamps();

        $table->unique(
            ['Shippers_Id','Shippers_Destination_Id','Shippers_Vehicle_Id'],
            'uniq_heavy_rate_combo'
        );

        $table->foreign('Shippers_Id')
            ->references('id')->on('Shippers_Master_T')
            ->onDelete('no action');   // MSSQL NO ACTION

        $table->foreign('Shippers_Destination_Id')
            ->references('id')->on('Shipper_Destinations_T')
            ->onDelete('no action');   // MSSQL NO ACTION

        $table->foreign('Shippers_Vehicle_Id')
            ->references('id')->on('Heavy_Vehicles_T')
            ->onDelete('no action');   // MSSQL NO ACTION
    });
}



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
           Schema::dropIfExists('Shipper_Heavy_Rates_T');
        Schema::dropIfExists('Heavy_Vehicles_T');
        Schema::dropIfExists('Shipper_Weight_Rates_T');
        Schema::dropIfExists('Shipper_Volume_Rates_T');
        Schema::dropIfExists('Shipper_Shipping_Rates_T');
        Schema::dropIfExists('Shipper_Destinations_T');
        Schema::dropIfExists('Shipper_Contacts_T');
        Schema::dropIfExists('Shippers_Master_T');
    }
};
