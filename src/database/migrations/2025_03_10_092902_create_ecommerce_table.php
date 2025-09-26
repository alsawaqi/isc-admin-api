<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {


        Schema::create('Geox_Country_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('Country_Code', 30)->unique()->nullable();
            $table->string('Country_Name', 50)->nullable();
            $table->string('Country_Name_Ar', 50)->nullable();
            $table->boolean('Operating_Country')->nullable();
            $table->char('Updated_Status')->nullable();

            $table->foreignId('Created_By', 12)
                   ->constrained('Secx_Admin_User_Master_T')
                   ->onDelete('no action')
                   ->nullable();
           $table->dateTime('Created_Date')->nullable();        
           $table->timestamps();



           
        });


        Schema::create('Geox_Region_Master_T', function (Blueprint $table) {

            $table->id();
            $table->string('Region_Code', 30)->unique()->nullable();
            $table->foreignId('Country_Id', 12)
                   ->constrained('Geox_Country_Master_T')
                   ->onDelete('no action')
                   ->nullable();
            $table->string('Region_Name', 50)->nullable();
            $table->string('Region_Name_Ar', 50)->nullable();
            $table->foreignId('Created_By', 12)
                   ->constrained('Secx_Admin_User_Master_T')
                   ->onDelete('no action')
                   ->nullable(); 
            $table->char('Updated_Status')->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->timestamps();



        });


        Schema::create('Geox_State_Master_T', function (Blueprint $table) {

            $table->id();
            $table->string('State_Code', 30)->unique()->nullable();
            $table->string('Country_Code', 5)->nullable();


            $table->foreignId('Country_Id', 12)
            ->constrained('Geox_Country_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->string('State_Name', 50)->nullable();
            $table->string('State_Name_Ar', 50)->nullable();
           $table->char('Updated_Status')->nullable();

            $table->foreignId('Created_By', 12)
                   ->constrained('Secx_Admin_User_Master_T')
                   ->onDelete('no action')
                   ->nullable();

           $table->dateTime('Created_Date')->nullable();
           $table->timestamps();



        });


        Schema::create('Geox_Zone_Master_T', function (Blueprint $table) {


            $table->id();
            $table->string('Zone_Code', 30)->unique()->nullable();


            $table->foreignId('Region_Id', 12)
            ->constrained('Geox_Region_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->foreignId('State_Id', 12)
            ->constrained('Geox_State_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->string('Zone_Name', 50)->nullable();
            $table->string('Zone_Name_Ar', 50)->nullable();

            $table->char('Updated_Status')->nullable();

            $table->foreignId('Created_By', 12)
                   ->constrained('Secx_Admin_User_Master_T')
                   ->onDelete('no action')
                   ->nullable();

           $table->dateTime('Created_Date')->nullable();
           $table->timestamps();



        });


        Schema::create('Geox_Jurisdiction_Master_T', function (Blueprint $table) {


            $table->id();
            $table->string('Jurisdiction_Code', 30)->unique()->nullable();
            $table->string('Company_Code', 5)->nullable();

            $table->foreignId('Zone_Id', 12)
            ->constrained('Geox_Zone_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->string('Jurisdiction_Name', 50)->nullable();
            $table->string('Jurisdiction_Name_Ar', 50)->nullable();

            $table->char('Updated_Status')->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->timestamps();


        });


        Schema::create('Geox_City_Master_T', function (Blueprint $table) {


            $table->id();

            $table->string('City_Code', 30)->unique()->nullable();

            $table->unsignedBigInteger('State_Id')->nullable();

            $table->foreign('State_Id')
                ->references('id')->on('Geox_State_Master_T')
                ->onDelete('no action');

            $table->string('City_Name', 50)->nullable();
            $table->string('City_Name_Ar', 50)->nullable();

            $table->char('Updated_Status')->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->timestamps();


        });




        Schema::create('Geox_Location_Master_T', function (Blueprint $table) {

            $table->id();
            $table->string('Location_Code', 30)->unique()->nullable();

            $table->foreignId('City_Id', 12)
            ->constrained('Geox_City_Master_T')
            ->onDelete('no action')
            ->nullable();


            $table->string('Location_Name', 50)->nullable();
            $table->string('Location_Name_Ar', 50)->nullable();
            $table->char('Updated_Status')->nullable();

            

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->timestamps();

        });



        Schema::create('Company_Types_Master_T', function (Blueprint $table) {

            $table->id();
            $table->string('Company_Type_Code', 30)->unique()->nullable();
            $table->string('Name',50);
            $table->string('Description',50)->nullable();
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();


        });




        Schema::create('Company_Master_T', function (Blueprint $table) {
            $table->id();

            $table->foreignId('Company_Type_Id')->constrained('Company_Types_Master_T')->nullable()->onDelete('no action');
            $table->string('Company_Code', 30)->unique()->nullable();

            $table->string('Company_Name', 50)->nullable();

            $table->string('Po_Box', 15)->nullable();
            $table->string('Postal_Code', 10)->nullable();
            $table->string('Country_Code', 12)->nullable();
            $table->string('Region_Code', 12)->nullable();
            $table->string('Location_Code', 12)->nullable();
            $table->string('Head_Office_Location', 25)->nullable();
            $table->char('Company_Grade', 10)->nullable();
            $table->boolean('Operational_Status')->nullable();
            $table->string('Contact_Person', 50)->nullable();
            $table->string('Contact_Person_Gsm', 30)->nullable();
            $table->string('Telephone', 30)->nullable();
            $table->string('Fax', 30)->nullable();
            $table->string('Email', 100)->nullable();
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();

        });



        Schema::create('Company_Contacts_T', function (Blueprint $table) {
            $table->id();
            $table->string('Company_Contact_Code', 30)->unique()->nullable(); // Renamed from Customer_Contact_Id
            $table->enum('Type', ['physical', 'shipping','billing'])->nullable();

            // Adjusted to refer to your correct main customer table (assuming 'Customers_Master_T')
            $table->foreignId('Company_Id')->nullable()->constrained('Company_Master_T')->onDelete('no action');

            $table->string('Customer_Code', 12)->nullable(); // Renamed from Customer_Id
            $table->string('Customer_Contact_Code', 12)->nullable(); // Renamed from Customer_Contact_Id

            $table->text('Address')->nullable();


            $table->foreignId('City_Id', 12)
            ->constrained('Geox_City_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->foreignId('State_Id', 12)
            ->constrained('Geox_State_Master_T')
            ->onDelete('no action')
            ->nullable();



            $table->foreignId('Country_Id', 12)
            ->constrained('Geox_Country_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->string('Postal_code')->nullable();
            $table->string('Phone')->nullable();

            // Added from MSSQL structure with renamed fields to avoid conflict
            $table->string('Contact_Person_Name', 150)->nullable();
            $table->string('Telephone', 30)->nullable();
            $table->string('Fax', 30)->nullable();
            $table->string('Gsm', 30)->nullable();
            $table->string('Email_Address', 50)->nullable(); // Renamed from Email
            $table->string('Designation', 50)->nullable();
            $table->string('Remarks', 200)->nullable();
            $table->string('Contact_User_Code', 12)->nullable(); // Renamed from User_Id
           $table->char('Updated_Status')->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->timestamps();

        });







        Schema::create('Customers_Types_Master_T', function (Blueprint $table) {

            $table->id();
            $table->string('Customer_Type_Code', 30)->unique()->nullable();
            $table->string('Name',50);
            $table->string('Description',50)->nullable();
            $table->timestamps();


        });



         // Customers Table
         Schema::create('Customers_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('Customer_Code', 30)->unique()->nullable(); // Renamed from Customer_Id

            

            $table->foreignId('Customer_Type_Id')
                  ->constrained('Customers_Types_Master_T')
                  ->nullable()
                  ->onDelete('no action');
                  
            $table->foreignId('User_Id')
                  ->constrained('Secx_User_Master_T')
                  ->onDelete('no action');

            $table->string('Customer_Id', 12)->nullable(); // Renamed from Customer_Id
            $table->string('Customer_Full_Name', 150)->nullable(); // Renamed from Customer_Name
            $table->integer('Customer_Type_Code')->nullable();
            $table->boolean('Customer_Status')->nullable();
            $table->float('Credit_Limit')->nullable();
            $table->string('Po_Box', 20)->nullable();
            $table->string('Postal_Code', 20)->nullable();

            $table->unsignedBigInteger('Country_Id')->nullable();

            $table->foreign('Country_Id')
                ->references('id')->on('Geox_Country_Master_T')
                ->onDelete('no action');  

            $table->unsignedBigInteger('Region_Id')->nullable();

            $table->foreign('Region_Id')
                ->references('id')->on('Geox_Region_Master_T')
                ->onDelete('no action');

            $table->unsignedBigInteger('Location_Id')->nullable();

            $table->foreign('Location_Id')
                ->references('id')->on('Geox_Location_Master_T')
                ->onDelete('no action');

            
            $table->string('GL_Account_No', 20)->nullable();
            $table->string('Telephone', 30)->nullable();
            $table->string('Fax', 30)->nullable();
            $table->string('Gsm', 30)->nullable();
            $table->string('Email_Address', 50)->nullable(); // Renamed from Email
            $table->string('Currency_Code', 12)->nullable();
            $table->string('Report_Code', 12)->nullable();
            $table->string('Customer_User_Code', 12)->nullable(); // Renamed from User_Id to avoid FK conflict
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();

            $table->integer('Loyalty_Points')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });


        Schema::create('Merchants_Master_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('Company_Master_T')->onDelete('no action')->nullable();

            $table->string('Merchant_Code', 30)->unique()->nullable(); // Renamed from Merchant_Code


            $table->string('Merchant_Name', 150);
            $table->boolean('Operating_Merchant');
            $table->boolean('Merchant_Status');

            // ────── Optional (nullable) fields ──────
            $table->string('Mode', 50)->nullable();
            $table->integer('Jurisdiction_Code')->nullable();
            $table->string('Zone_Code', 12)->nullable();
            $table->boolean('Allow_FOC')->nullable();
            $table->boolean('Fuel_Supported_System')->nullable();
            $table->string('Po_Box', 20)->nullable();
            $table->string('Postal_Code', 20)->nullable();
            $table->string('Telephone', 30)->nullable();
            $table->string('Fax', 30)->nullable();
            $table->string('Gsm', 30)->nullable();
            $table->string('Email', 50)->nullable();
            $table->string('Country_Code', 12)->nullable();
            $table->string('Region_Code', 12)->nullable();
            $table->string('Location_Code', 12)->nullable();
            $table->integer('No_Of_Tanks')->nullable();
            $table->integer('No_Of_Dispensers')->nullable();

            // Receipt headers / footers
            $table->string('Receipt_Header1', 50)->nullable();
            $table->string('Receipt_Header2', 50)->nullable();
            $table->string('Receipt_Header3', 50)->nullable();
            $table->string('Receipt_Header4', 50)->nullable();
            $table->string('Receipt_Footer1', 50)->nullable();
            $table->string('Receipt_Footer2', 50)->nullable();
            $table->string('Receipt_Footer3', 50)->nullable();
            $table->string('Receipt_Footer4', 50)->nullable();

            // Dates & misc.
            $table->dateTime('Installation_Date')->nullable();
            $table->dateTime('Gone_Live_On')->nullable();
            $table->string('Version_Control', 10)->nullable();
            $table->string('User_Id', 12)->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();

        });


       // Customer Addresses Table (Physical & Shipping Address)
        Schema::create('Customers_Contact_T', function (Blueprint $table) {
            $table->id();
            $table->string('Customer_Contact_Code', 30)->unique()->nullable(); 

            $table->enum('Type', ['physical', 'shipping'])->nullable();

            $table->foreignId('Customers_Contact_Id')
                   ->constrained('Customers_Master_T')
                   ->onDelete('no action')
                   ->nullable();

            $table->foreignId('City_Id', 12)
            ->constrained('Geox_City_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->unsignedBigInteger('State_Id')->nullable();

            $table->foreign('State_Id')
                ->references('id')->on('Geox_State_Master_T')
                ->onDelete('no action');

       

          $table->foreignId('Country_Id', 12)
            ->constrained('Geox_Country_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->string('Contact_Person_Name', 150)->nullable();
            $table->string('Telephone', 30)->nullable();
            $table->string('Fax', 30)->nullable();
            $table->string('Gsm', 30)->nullable();
            $table->string('Email', 50)->nullable(); // Renamed from Email
            $table->string('Designation', 50)->nullable();
            $table->string('Remarks', 200)->nullable();
            $table->dateTime('Created_date')->nullable();
            $table->char('Updated_status', 1)->nullable();
            $table->timestamps();

        });




        // Categories Table with Support for Multiple Subcategories
        Schema::create('Products_Departments_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Department_Code', 30)->unique()->nullable();
            $table->string('Product_Department_Name')->nullable();
            $table->string('Product_Department_Name_Ar')->nullable();
            $table->char('Touch_Screen_Status')->nullable();
            $table->char('Stock_Control_Status')->nullable();
            $table->string('Image_path')->nullable();
            $table->integer('Image_Size')->nullable();
            $table->string('Image_Extension', 10)->nullable();
            $table->string('Image_Type', 50)->nullable();
            $table->datetime('Created_Date')->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->char('Updated_Status', 1)->nullable();

            
            $table->timestamps();
        });


        Schema::create('Products_Sub_Department_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('Products_Departments_Id')
                    ->constrained('Products_Departments_T')
                    ->onDelete('no action');

            $table->string('Products_Sub_Department_Code', 30)
                  ->unique()
                  ->nullable(); // Renamed from Product_Sub_Department_Id
            
            $table->string('Sub_Department_Name',50)->unique();
            $table->string('Sub_Department_Name_Ar',50)->unique();
            $table->text('Sub_Department_Description',50)->nullable();
            $table->string('Image_path')->nullable();
            $table->integer('Image_Size')->nullable();
            $table->string('Image_Extension', 10)->nullable();
            $table->string('Image_Type', 50)->nullable();
            $table->datetime('Created_Date')->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->char('Updated_Status', 1)->nullable();

            $table->timestamps();
        });


        Schema::create('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            $table->id();

            $table->string('Slug')->nullable();

            $table->foreignId('Product_Sub_Department_Id')
                    ->constrained('Products_Sub_Department_T')
                    ->onDelete('no action');

             $table->string('Product_Sub_Sub_Department_Code', 30)->unique()->nullable();
            $table->string('Product_Sub_Sub_Department_Name',50)->unique();
            $table->string('Product_Sub_Sub_Department_Name_Ar',50)->unique();
            $table->string('Product_Sub_Sub_Department_Description',50)->nullable();
            $table->string('Image_Path')->nullable();
            $table->integer('Image_Size')->nullable();
            $table->string('Image_Extension', 10)->nullable();
            $table->string('Image_Type', 50)->nullable();
            $table->datetime('Created_Date')->nullable();
             $table->foreignId('Created_By', 12)
                            ->constrained('Secx_Admin_User_Master_T')
                            ->onDelete('no action')
                            ->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();
        });


        Schema::create('Products_Types_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Types_Code', 30)->unique()->nullable();
            $table->string('Product_Types_Name')->unique();
            $table->string('Product_Types_Description')->nullable();
            $table->boolean('Default_Product_Type')->nullable();
            $table->datetime('Created_Date')->nullable();
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();
          
         });


         Schema::create('Products_Manufacture_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Manufacture_Code', 30)->unique()->nullable(); // Renamed from Product_Types_Id
            $table->string('Products_Manufacture_Name')->unique();
            $table->string('Products_Manufacture_Name_Ar')->nullable()->unique();
            $table->foreignId('Product_Department_Id')->nullable()->constrained('Products_Departments_T')->onDelete('no action');
            $table->string('Products_Manufacture_Description')->nullable();

            $table->foreignId('Manufacturer_Country_Code', 12)
                   ->nullable()
                   ->constrained('Geox_Country_Master_T')
                   ->onDelete('no action');

            $table->datetime('Created_Date')->nullable();
            $table->foreignId('Created_By', 12)
                  ->nullable()
                  ->constrained('Secx_Admin_User_Master_T')
                  ->onDelete('no action');

            $table->char('Updated_Status', 1)->nullable();

            $table->timestamps();
        });

        Schema::create('Products_Brands_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Brand_Code', 30)->unique()->nullable(); // Renamed from Product_Brand_Id
            $table->string('Products_Brands_Name')->unique();
            $table->string('Products_Brands_Name_Ar')->nullable();
            $table->string('Products_Brands_Description')->nullable();
            
            $table->datetime('Created_Date')->nullable();
             $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();
        });
 
       

        // Products Table
        Schema::create('Products_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Code', 30)->unique()->nullable(); // Renamed from Products_Id
             $table->string('Slug')->nullable();
            $table->foreignId('Product_Department_Id')->constrained('Products_Departments_T')->onDelete('no action');
            $table->foreignId('Product_Sub_Department_Id')->constrained('Products_Sub_Department_T')->onDelete('no action');
            $table->foreignId('Product_Sub_Sub_Department_Id')->constrained('Products_Sub_Sub_Department_T')->onDelete('no action');

            $table->unsignedBigInteger('Product_Type_Id')->nullable();
            $table->foreign('Product_Type_Id')->references('id')->on('Products_Types_Master_T')->onDelete('no action');

            $table->unsignedBigInteger('Product_Brand_Id')->nullable();
            $table->foreign('Product_Brand_Id')->references('id')->on('Products_Brands_Master_T')->onDelete('no action');

            $table->unsignedBigInteger('Product_Manufacture_Id')->nullable();
            $table->foreign('Product_Manufacture_Id')->references('id')->on('Products_Manufacture_Master_T')->onDelete('no action');

            $table->string('Product_Name')->index();
            $table->string('Product_Name_Ar')->index();
            $table->text('Product_Description');
            $table->decimal('Product_Price', 10, 2);
            $table->integer('Product_Stock');

            $table->enum('Status', ['available', 'out_of_stock', 'discontinued'])->default('available');


            $table->boolean('Minor_Item')->nullable();
            $table->string('Brand_Code', 5)->nullable();
            $table->string('Manufacturer_Code', 150)->nullable();
            $table->string('Product_User_Code', 12)->nullable(); // renamed from User_Id

            $table->boolean('Barcode_Changed_Status')->nullable();
            $table->boolean('Inhouse_Barcode')->nullable();
            $table->string('Inhouse_Barcode_Source', 50)->nullable();
            $table->integer('Product_Owner_Jurisdiction')->nullable();
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();

            $table->timestamps();
        });



        Schema::create('Merchant_Product_Stock_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('Merchant_Id')->constrained('Merchants_Master_T')->onDelete('no action');
            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
            $table->integer('Stock');
            $table->decimal('Price', 10, 2);
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();

        });



        Schema::create('Product_Supplier_BarCode_T', function (Blueprint $table) {
            $table->id();

            $table->string('Product_Barcode_Code', 30)->unique()->nullable(); // Renamed from Product_Barcode_Id
            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
            $table->string('Alternate_Unit_Code')->nullable(); // Renamed from Alternate_Unit_Id
            $table->string('Supplier_Barcode')->unique()->nullable(); // Renamed from Alternate_Unit_Id
            $table->integer('Created_Jurisdiction_Code')->nullable();
            $table->integer('InHouse_Barcode')->nullable();
            $table->string('InHouse_Barcode_Type')->nullable();
            $table->string('InHouse_Barcode_Format')->nullable();
            $table->char('Updated_Status', 1)->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->timestamps();



        });


        DB::statement('CREATE UNIQUE INDEX idx_alternate_unit_code_not_null ON Product_Supplier_BarCode_T (Alternate_Unit_Code) WHERE Alternate_Unit_Code IS NOT NULL');


        Schema::create('Products_Packs_Master_T', function (Blueprint $table) {
            $table->id();

            $table->string('Product_Pack_Code', 30)->unique()->nullable(); // Renamed from Product_Pack_Id

            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
           

            $table->string('Alternate_Unit_Code', 12); // nvarchar(12) NOT NULL
            $table->float('Conversion_Factor')->nullable(); // float NULL
            $table->boolean('Base_Unit')->nullable(); // bit NULL
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();

            $table->timestamps();


        });



        // Product Images Table
        Schema::create('Products_Images_T', function (Blueprint $table) {
            $table->id();

            $table->string('Product_Image_Code', 30)->unique()->nullable(); // Renamed from Product_Image_Id
            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
            $table->string('Image_Path')->nullable();
            $table->integer('Image_Size')->nullable();
            $table->string('Image_Extension', 10)->nullable();
            $table->string('Image_Type', 50)->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();
        });



        Schema::create('Products_Datasheet_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Datasheet_Code', 17)->unique()->nullable(); // Renamed from Product_Datasheet_Id
            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
            $table->string('Datasheet_Description')->nullable();
            $table->string('Datasheet_Variable')->nullable();
            $table->string('Datasheet_Value')->nullable();
            $table->timestamps();

        });
       // Wish List Table
        Schema::create('Customers_Wish_Lists_T', function (Blueprint $table) {
            $table->id();
            $table->string('Wish_List_Code', 30)->unique()->nullable(); // Renamed from Wish_List_Id
            $table->foreignId('Customers_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();
        });

        // Cart Table
        Schema::create('Customers_Carts_T', function (Blueprint $table) {
            $table->id();
            $table->string('Cart_Code', 30)->unique()->nullable(); // Renamed from Cart_Id
            $table->foreignId('Customers_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
            $table->integer('Quantity');
            $table->timestamps();
        });

         // Orders Table
         Schema::create('Orders_Placed_T', function (Blueprint $table) {
            $table->id();
            $table->string('Order_Code', 30)->unique()->nullable(); // Renamed from Orders_Placed_Id
            $table->string('Transaction_Number')->unique();
            $table->foreignId('Customers_Contacts_Id')->constrained('Customers_Contact_T')->onDelete('no action');  
            $table->foreignId('Customers_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->decimal('Total_Price', 10, 3);
            $table->enum('Status', ['pending', 'processing', 'packed', 'dispatched', 'shipped', 'delivered', 'cancelled'])->default('pending');
           $table->timestamps();
        });

        // Order Items Table
        Schema::create('Orders_Placed_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('Order_Placed_Code', 30)->unique()->nullable(); // Renamed from Order_Item_Id
            $table->foreignId('Orders_Placed_Id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->foreignId('Cart_Id')
                    ->constrained('Customers_Carts_T')
                    ->nullable()
                    ->onDelete('no action');
            $table->foreignId('Products_Id')->constrained('Products_Master_T')->onDelete('no action');
            $table->integer('Quantity')->nullable();
            $table->decimal('Price', 10, 2)->nullable();
            $table->decimal('Subtotal', 10, 2)->nullable();
            $table->decimal('Vat', 10, 2)->nullable();
            $table->enum('Status', ['pending', 'processing', 'packed', 'dispatched', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->timestamps();
        });


        // Order Packaging Table
        Schema::create('Orders_Packaging_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('Packaging_Code', 30)->unique()->nullable(); // Renamed from Packaging_Id
            $table->foreignId('Orders_Placed_Id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->foreignId('Orders_Placed_Details_Id')->constrained('Orders_Placed_Details_T')->onDelete('no action'); // 👈 FIXED
            $table->string('Unpacked_Image')->nullable();
            $table->string('Packed_Image')->nullable();
            $table->foreignId('Packed_By')->nullable()->constrained('Secx_Admin_User_Master_T')->onDelete('set null');
            $table->timestamps();
        });


        // Shipments Table
        Schema::create('Orders_Shipments_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('Shipment_Code', 30)->unique()->nullable(); // Renamed from Shipment_Id
            $table->foreignId('Orders_Placed_Id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->string('Tracking_Number')->unique();
            $table->enum('Status', ['dispatched', 'shipped', 'in_transit', 'delivered', 'returned'])->default('dispatched');
            $table->timestamps();
        });

        // Financial Transactions Table
        Schema::create('Orders_Financial_Transactions_T', function (Blueprint $table) {
            $table->id();
            $table->string('Financial_Transaction_Code', 30)->unique()->nullable(); // Renamed from Financial_Transaction_Id
            $table->foreignId('Orders_Placed_Id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->foreignId('Orders_Placed_Details_Id')->constrained('Orders_Placed_Details_T')->onDelete('no action');
            $table->string('Transaction_Reference')->unique();
            $table->enum('Status', ['pending', 'posted', 'reconciled'])->default('pending');
            $table->timestamps();
        });

        // Order Fulfillment Issues Table
        Schema::create('Orders_Customers_Grievances_T', function (Blueprint $table) {
            $table->id();
            $table->string('Orders_Customers_Grievances_Code', 30)->unique()->nullable(); // Renamed from Grievance_Id
            $table->foreignId('Orders_Placed_Id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->foreignId('Orders_Placed_Details_Id')->constrained('Orders_Placed_Details_T')->onDelete('no action');

            $table->enum('Issue_Status', ['pending_review', 'resolved', 'cancelled'])->default('pending_review');
            $table->text('Resolution_Details')->nullable();
            $table->timestamps();
        });

        // Sales Returns Table
        Schema::create('Sales_Returns_T', function (Blueprint $table) {
            $table->id();
            $table->string('sales_return_code', 30)->unique()->nullable(); // Renamed from Sales_Return_Id
            $table->foreignId('Orders_Placed_Id')->constrained('orders_placed_t')->onDelete('no action');
            $table->foreignId('Orders_Placed_Details_Id')->constrained('Orders_Placed_Details_T')->onDelete('no action');
            $table->foreignId('Products_Id')->nullable()->constrained('Products_Master_T')->onDelete('no action');
            $table->text('Reason');
            $table->enum('Status', ['pending', 'approved', 'rejected', 'processed'])->default('pending');
            $table->timestamps();
        });

        Schema::create('Sales_Transaction_Header_T', function (Blueprint $table) {

            $table->id();

            $table->string('Sales_Transaction_Header_code', 30)->unique()->nullable();

            $table->string('Merchant_Id', 12)->nullable();
            $table->string('Bill_No', 20)->nullable();

            $table->dateTime('Bill_DateTime')->nullable();
            $table->smallInteger('Status')->nullable();
            $table->integer('Transaction_Type_Code')->nullable();
            $table->string('Customer_Id', 12)->nullable();
            $table->string('Currency_Code', 12)->nullable();
            $table->float('Amount_Tendered')->nullable();
            $table->float('Exchange_Rate')->nullable();
            $table->float('Transaction_Total')->nullable();
            $table->float('Amount_Returned')->nullable();
            $table->float('Amount_Due')->nullable();
            $table->dateTime('Last_Due_Date')->nullable();
            $table->string('Invoice_Status', 10)->nullable();
            $table->string('Invoice_No', 12)->nullable();
            $table->unsignedBigInteger('Shift_No')->nullable();
            $table->smallInteger('Bill_PrintCount')->nullable();
            $table->string('Card_Code', 5)->nullable();
            $table->string('Card_Number', 50)->nullable();
            $table->unsignedBigInteger('Settlement_Code')->nullable();
            $table->string('Bill_Reference', 200)->nullable();
            $table->string('User_Id', 12)->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();

            $table->unique(['Merchant_Id', 'Bill_No']); // Unique but not primary
            $table->timestamps();

        });

        Schema::create('Sales_Transactions_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('Sales_Transactions_Details_code',30)->unique()->nullable();

            $table->string('Transaction_No', 25);
            $table->string('Merchant_Id', 12);
            $table->string('Bill_No', 20);

            $table->string('Product_Code', 17)->nullable();
            $table->string('Unit_Code', 12)->nullable();
            $table->string('Batch_No', 12)->nullable();

            $table->float('Quantity')->nullable();
            $table->float('Conversion_Factor')->nullable();
            $table->float('Rate')->nullable();

            $table->float('Sub_Total_Amount_UnDiscount')->nullable();
            $table->float('Discount_Amount')->nullable();
            $table->float('VAT_Tax_Amount')->nullable();
            $table->float('Sub_Total_Amount_Discount')->nullable();

            $table->integer('Tank_Code')->nullable();
            $table->integer('Dispenser_Code')->nullable();
            $table->integer('Hose_Number')->nullable();
            $table->tinyInteger('Return_Status')->nullable();

            $table->string('Campaign_Reference', 12)->nullable();
            $table->unsignedBigInteger('Shift_No')->nullable();
            $table->integer('Fuel_Transaction_No')->nullable();
            $table->boolean('Locked')->nullable();
            $table->unsignedBigInteger('Settlement_Code')->nullable();

            $table->dateTime('Transaction_Date')->nullable();
            $table->float('Variance_Amount')->nullable();
            $table->string('Smart_Card_Txn_Reference', 15)->nullable();
            $table->string('User_Id', 12)->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();

            $table->unique(['Merchant_Id', 'Bill_No']); // Unique but not primary
            $table->timestamps();
        });



        // Defective Product Returns Table
        Schema::create('Defective_Products_Returns_T', function (Blueprint $table) {
            $table->id();
            $table->string('Defective_Product_Return_Code', 30)->unique()->nullable(); // Renamed from Defective_Product_Return_Id
            $table->foreignId('Sales_Return_Id')->constrained('Sales_Returns_T')->onDelete('no action');
            $table->text('Defect_Description');
            $table->text('Resolution')->nullable();
            $table->timestamps();
        });

      //Feedback questions
      Schema::create('Orders_Feedbacks_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('Feedback_Question_Code', 30)->unique()->nullable(); // Renamed from Feedback_Question_Id
            $table->string('Question');
            $table->timestamps();
        });

        // Order Feedback Table
        Schema::create('Orders_Customers_Feedback_T', function (Blueprint $table) {
            $table->id();
            $table->string('Orders_Customers_Feedback_Code', 30)->unique()->nullable(); // Renamed from Feedback_Id
            $table->foreignId('Orders_Placed_Details_Id')->constrained('Orders_Placed_Details_T')->onDelete('no action');

            $table->foreignId('Customer_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->foreignId('Feedback_Question_Id')->constrained('Orders_Feedbacks_Master_T')->onDelete('no action');
            $table->boolean('Response')->default(false);
            $table->timestamps();
        });

        // Loyalty Transactions Table
        Schema::create('Customers_Loyalty_Transactions_T', function (Blueprint $table) {
            $table->id();
            $table->string('Loyalty_Transaction_Code', 30)->unique()->nullable(); // Renamed from Loyalty_Transaction_Id
            $table->foreignId('Customer_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->foreignId('Orders_Placed_Id')->nullable()->constrained('Orders_Placed_T')->onDelete('set null');
            $table->integer('Points_Earned')->default(0);
            $table->integer('Points_Redeemed')->default(0);
            $table->timestamps();
        });


        Schema::create('Customers_Loyalty_T', function (Blueprint $table) {
            $table->id();
            $table->string('Customers_Loyalty_Code', 30)->unique()->nullable(); // Renamed from Loyalty_Id
            $table->foreignId('Customer_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->integer('Points_Earned')->default(0);
            $table->integer('Points_Redeemed')->default(0);
            $table->timestamps();
        });

          // Roles Table (Spatie)
          Schema::create('Security_Roles_T', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name');
            $table->timestamps();
        });

        // Permissions Table (Spatie)
        Schema::create('Security_Permissions_T', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name');
            $table->timestamps();
        });

        // Role-Permission Table (Spatie)
        Schema::create('Security_Role_Has_Permissions_T', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('Security_Roles_T')->onDelete('no action');
            $table->foreignId('permission_id')->constrained('Security_Permissions_T')->onDelete('no action');
            $table->primary(['role_id', 'permission_id']);
        });

       // Model-Has-Roles Table (Spatie)
        Schema::create('Security_Model_Has_Roles_T', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('Security_Roles_T')->onDelete('no action');
            $table->morphs('model');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        // Model-Has-Permissions Table (Spatie)
        Schema::create('Security_Model_Has_Permissions_T', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('Security_Permissions_T')->onDelete('no action');
            $table->morphs('model');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });


       // Distributors Table
        Schema::create('Colx_Distributors_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('Customer_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->string('Company_Name');
            $table->text('Business_Details');
            $table->string('Region');
            $table->timestamps();
        });

        // Credit Customers Table
        Schema::create('Credit_Customers_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('Customer_Id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->decimal('Credit_Limit', 10, 2);
            $table->decimal('Balance_Due', 10, 2)->default(0);
            $table->timestamps();
        });

        // Collaborative Projects Table
        Schema::create('Colx_Collaborative_Projects_T', function (Blueprint $table) {
            $table->id();
            $table->string('Project_Title');
            $table->text('Description');
            $table->foreignId('Customer_Id')->nullable()->constrained('Customers_Master_T')->onDelete('set null');
            $table->timestamps();
        });

        // Freelancers Table
        Schema::create('Colx_Freelancers_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('Customer_Id')->nullable()->constrained('Customers_Master_T')->onDelete('set null');
            $table->string('Skillset');
            $table->text('Experience');
            $table->timestamps();
        });

        // Internships Table
        Schema::create('Colx_Internships_T', function (Blueprint $table) {
            $table->id();
            $table->string('Title');
            $table->text('Description');
            $table->foreignId('Customer_Id')->nullable()->constrained('Customers_Master_T')->onDelete('set null');
            $table->timestamps();
        });

        // Training Courses Table
        Schema::create('Colx_Training_Courses_T', function (Blueprint $table) {
            $table->id();
            $table->string('Course_Name');
            $table->text('Description');
            $table->decimal('Price', 10, 2);
            $table->timestamps();
        });

        // Job Positions Table
        Schema::create('Colx_Job_Positions_T', function (Blueprint $table) {
            $table->id();
            $table->string('Title');
            $table->text('Description');
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('order_feedback');
        Schema::dropIfExists('defective_product_returns');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('order_fulfillment_issues');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('order_packaging');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('training_courses');
        Schema::dropIfExists('internships');
        Schema::dropIfExists('freelancers');
        Schema::dropIfExists('collaborative_projects');
        Schema::dropIfExists('credit_customers');
        Schema::dropIfExists('distributors');
    }
};
