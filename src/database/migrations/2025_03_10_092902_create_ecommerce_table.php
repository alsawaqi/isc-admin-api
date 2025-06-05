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
           $table->timestamps();



        });


        Schema::create('Geox_Region_Master_T', function (Blueprint $table) {

            $table->id();
            $table->string('Region_Code', 30)->unique()->nullable();
            $table->string('Country_Code', 5)->nullable();

            $table->foreignId('Country_Id', 12)
            ->constrained('Geox_Country_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->string('Region_Name', 50)->nullable();
            $table->string('Region_Name_Ar', 50)->nullable();
           $table->char('Updated_Status')->nullable();

            $table->foreignId('Created_By', 12)
                   ->constrained('Secx_Admin_User_Master_T')
                   ->onDelete('no action')
                   ->nullable();


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

            $table->timestamps();


        });


        Schema::create('Geox_City_Master_T', function (Blueprint $table) {


            $table->id();

            $table->string('City_Code', 30)->unique()->nullable();

            $table->foreignId('Jurisdiction_Id', 12)
            ->constrained('Geox_Jurisdiction_Master_T')
            ->onDelete('no action')
            ->nullable();


            $table->string('City_Name', 50)->nullable();
            $table->string('City_Name_Ar', 50)->nullable();

            $table->char('Updated_Status')->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();

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

            $table->timestamps();

        });



        Schema::create('Company_Types_Master_T', function (Blueprint $table) {

            $table->id();
            $table->string('Company_Type_Code', 30)->unique()->nullable();
            $table->string('Name',50);
            $table->string('Description',50)->nullable();
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
            $table->string('User_Id', 12)->nullable();
            $table->dateTime('Created_Date')->nullable();
            $table->char('Updated_Status', 1)->nullable();
            $table->timestamps();

        });



        Schema::create('Company_Contacts_T', function (Blueprint $table) {
            $table->id();
            $table->string('Company_Contact_Code', 30)->unique()->nullable(); // Renamed from Customer_Contact_Id
            $table->enum('Type', ['physical', 'shipping'])->nullable();

            // Adjusted to refer to your correct main customer table (assuming 'Customers_Master_T')
            $table->foreignId('company_id')->nullable()->constrained('Company_Master_T')->onDelete('no action');

            $table->string('Customer_Code', 12)->nullable(); // Renamed from Customer_Id
            $table->string('customer_contact_code', 12)->nullable(); // Renamed from Customer_Contact_Id

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
            $table->string('contact_person_name', 150)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('fax', 30)->nullable();
            $table->string('gsm', 30)->nullable();
            $table->string('email_address', 50)->nullable(); // Renamed from Email
            $table->string('designation', 50)->nullable();
            $table->string('remarks', 200)->nullable();
            $table->string('contact_user_code', 12)->nullable(); // Renamed from User_Id
            $table->dateTime('created_date')->nullable();
            $table->char('updated_status', 1)->nullable();
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
            $table->foreignId('Customer_Type_Id')->constrained('Customers_Types_Master_T')->onDelete('no action');
            $table->foreignId('User_Id')->constrained('Secx_User_Master_T')->onDelete('no action');
            $table->string('Customer_Id', 12)->nullable(); // Renamed from Customer_Id
            $table->string('Customer_Full_Name', 150)->nullable(); // Renamed from Customer_Name
            $table->integer('Customer_Type_Code')->nullable();
            $table->boolean('Customer_Status')->nullable();
            $table->float('Credit_Limit')->nullable();
            $table->string('Po_Box', 20)->nullable();
            $table->string('Postal_Code', 20)->nullable();



            $table->foreignId('Country_Id', 12)
            ->constrained('Geox_Country_Master_T')
            ->onDelete('no action')
            ->nullable();





            $table->foreignId('Region_Id', 12)
            ->constrained('Geox_Region_Master_T')
            ->onDelete('no action')
            ->nullable();



            $table->foreignId('Location_Id', 12)
            ->constrained('Geox_Location_Master_T')
            ->onDelete('no action')
            ->nullable();


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
            $table->string('Customer_Contact_Code', 30)->unique()->nullable(); // Renamed from Customer_Contact_Id
            $table->enum('Type', ['physical', 'shipping'])->nullable();

            // Adjusted to refer to your correct main customer table (assuming 'Customers_Master_T')
            $table->foreignId('Customer_Id')->nullable()->constrained('Customers_Master_T')->onDelete('no action');

         


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
            $table->string('contact_person_name', 150)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('fax', 30)->nullable();
            $table->string('gsm', 30)->nullable();
            $table->string('email_address', 50)->nullable(); // Renamed from Email
            $table->string('designation', 50)->nullable();
            $table->string('remarks', 200)->nullable();
            $table->string('contact_user_code', 12)->nullable(); // Renamed from User_Id
            $table->dateTime('created_date')->nullable();
            $table->char('updated_status', 1)->nullable();
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
            $table->string('image_path')->nullable();
            $table->integer('size')->nullable();
            $table->string('extension', 10)->nullable();
            $table->string('type', 50)->nullable();
            $table->char('updated_status', 1)->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->timestamps();
        });


        Schema::create('Products_Sub_Department_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Sub_Department_Code', 30)->unique()->nullable(); // Renamed from Product_Sub_Department_Id
            $table->foreignId('product_department_id')->constrained('Products_Departments_T')->onDelete('no action');
            $table->string('name',50)->unique();
            $table->text('description',50)->nullable();
            $table->string('image_path')->nullable();
            $table->integer('size')->nullable();
            $table->string('extension', 10)->nullable();
            $table->string('type', 50)->nullable();
            $table->timestamps();
        });


        Schema::create('Products_Sub_Sub_Department_T', function (Blueprint $table) {
            $table->id();

             $table->string('Product_Sub_Sub_Department_Code', 30)->unique()->nullable();

            $table->foreignId('product_sub_department_id')->constrained('Products_Sub_Department_T')->onDelete('no action');

            $table->string('name',50)->unique();
            $table->string('description',50)->nullable();
            $table->string('image_path')->nullable();
            $table->integer('size')->nullable();
            $table->string('extension', 10)->nullable();
            $table->string('type', 50)->nullable();
            $table->timestamps();
        });


        Schema::create('Products_Types_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();


        });


        // Products Table
        Schema::create('Products_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Code', 30)->unique()->nullable(); // Renamed from Product_Id
            $table->foreignId('product_type_id')->constrained('Products_Types_Master_T')->onDelete('no action');
            $table->foreignId('product_sub_sub_department_id')->constrained('Products_Sub_Sub_Department_T')->onDelete('no action');
            $table->foreignId('product_sub_department_id')->constrained('Products_Sub_Department_T')->onDelete('no action');
            $table->foreignId('product_department_id')->constrained('Products_Departments_T')->onDelete('no action');

            $table->string('name')->index();
            $table->string('name_ar')->index();
            $table->text('description');
            $table->decimal('price', 10, 2);
            $table->integer('stock');

            $table->enum('status', ['available', 'out_of_stock', 'discontinued'])->default('available');


            $table->boolean('minor_item')->nullable();
            $table->string('brand_code', 5)->nullable();
            $table->string('manufacturer_code', 150)->nullable();
            $table->string('product_user_code', 12)->nullable(); // renamed from User_Id

            $table->boolean('barcode_changed_status')->nullable();
            $table->boolean('inhouse_barcode')->nullable();
            $table->string('inhouse_barcode_source', 12)->nullable();
            $table->integer('product_owner_jurisdiction')->nullable();
            $table->char('updated_status', 1)->nullable();

            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();

            $table->timestamps();
        });



        Schema::create('Merchant_Product_Stock_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('Merchants_Master_T')->onDelete('no action');
            $table->foreignId('product_id')->constrained('Products_Master_T')->onDelete('no action');
            $table->integer('stock');
            $table->decimal('price', 10, 2);
            $table->timestamps();

        });



        Schema::create('Products_Barcodes_T', function (Blueprint $table) {
            $table->id();

            $table->string('product_barcode_code', 17)->unique()->nullable(); // Renamed from Product_Barcode_Id
            $table->foreignId('product_id')->constrained('Products_Master_T')->onDelete('no action');
            $table->string('barcode')->unique();
            $table->string('barcode_type')->nullable();
            $table->string('barcode_format')->nullable();
            $table->timestamps();



        });


        Schema::create('Products_Packs_Master_T', function (Blueprint $table) {
            $table->id();

            $table->string('product_pack_code', 17)->unique()->nullable(); // Renamed from Product_Pack_Id
            $table->foreignId('product_id')->constrained('Products_Master_T')->onDelete('no action');
            $table->integer('quantity'); // Total items in the pack
            $table->decimal('price', 10, 2);

            $table->timestamps();


        });



        // Product Images Table
        Schema::create('Products_Images_T', function (Blueprint $table) {
            $table->id();

            $table->string('product_image_code', 17)->unique()->nullable(); // Renamed from Product_Image_Id
            $table->foreignId('product_id')->constrained('Products_Master_T')->onDelete('no action');
            $table->string('image_path')->nullable();
            $table->integer('size')->nullable();
            $table->string('extension', 10)->nullable();
            $table->string('type', 50)->nullable();
            $table->foreignId('Created_By', 12)
            ->constrained('Secx_Admin_User_Master_T')
            ->onDelete('no action')
            ->nullable();
            $table->timestamps();
        });



        Schema::create('Products_Datasheet_T', function (Blueprint $table) {
            $table->id();
            $table->string('Product_Datasheet_Code', 17)->unique()->nullable(); // Renamed from Product_Datasheet_Id
            $table->foreignId('product_id')->constrained('Products_Master_T')->onDelete('no action');
            $table->string('datasheet_description')->nullable();
            $table->string('datasheet_varible')->nullable();
            $table->string('datasheet_value')->nullable();
            $table->timestamps();

        });
       // Wish List Table
        Schema::create('Customers_Wish_Lists_T', function (Blueprint $table) {
            $table->id();
            $table->string('wish_list_code', 30)->unique()->nullable(); // Renamed from Wish_List_Id
            $table->foreignId('customer_id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->foreignId('product_id')->constrained('Products_Master_T')->onDelete('no action');
            $table->timestamps();
        });

        // Cart Table
        Schema::create('Customers_Carts_T', function (Blueprint $table) {
            $table->id();
            $table->string('cart_code', 30)->unique()->nullable(); // Renamed from Cart_Id
            $table->foreignId('customer_id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->foreignId('product_id')->constrained('Products_Master_T')->onDelete('no action');
            $table->integer('quantity');
            $table->timestamps();
        });

         // Orders Table
         Schema::create('Orders_Placed_T', function (Blueprint $table) {
            $table->id();
            $table->string('order_code', 30)->unique()->nullable(); // Renamed from Order_Id
            $table->string('transaction_number')->unique();

            $table->foreignId('customer_id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->decimal('total_price', 10, 2);
            $table->enum('status', ['pending', 'processing', 'packed', 'dispatched', 'shipped', 'delivered', 'cancelled'])->default('pending');
           $table->timestamps();
        });

        // Order Items Table
        Schema::create('Orders_Placed_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('order_Placed_code', 30)->unique()->nullable(); // Renamed from Order_Item_Id
            $table->foreignId('order_id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->foreignId('cart_id')->constrained('Customers_Carts_T')->onDelete('no action');
            $table->foreignId('product_id')->constrained('Products_Master_T')->onDelete('no action');
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('vat', 10, 2);
            $table->enum('status', ['pending', 'processing', 'packed', 'dispatched', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->timestamps();
        });


        // Order Packaging Table
        Schema::create('Orders_Packaging_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('packaging_code', 30)->unique()->nullable(); // Renamed from Packaging_Id
            $table->foreignId('order_id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->foreignId('orders_details_id')->constrained('Orders_Placed_Details_T')->onDelete('no action'); // 👈 FIXED
            $table->string('unpacked_image')->nullable();
            $table->string('packed_image')->nullable();
            $table->foreignId('packed_by')->nullable()->constrained('Secx_Admin_User_Master_T')->onDelete('set null');
            $table->timestamps();
        });


        // Shipments Table
        Schema::create('Orders_Shipments_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_code', 30)->unique()->nullable(); // Renamed from Shipment_Id
            $table->foreignId('order_id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->string('tracking_number')->unique();
            $table->enum('status', ['dispatched', 'shipped', 'in_transit', 'delivered', 'returned'])->default('dispatched');
            $table->timestamps();
        });

        // Financial Transactions Table
        Schema::create('Orders_Financial_Transactions_T', function (Blueprint $table) {
            $table->id();
            $table->string('financial_transaction_code', 30)->unique()->nullable(); // Renamed from Financial_Transaction_Id
            $table->foreignId('order_id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->foreignId('orders_details_id')->constrained('Orders_Placed_Details_T')->onDelete('no action');
            $table->string('transaction_reference')->unique();
            $table->enum('status', ['pending', 'posted', 'reconciled'])->default('pending');
            $table->timestamps();
        });

        // Order Fulfillment Issues Table
        Schema::create('Orders_Customers_Grievances_T', function (Blueprint $table) {
            $table->id();
            $table->string('orders_customers_grievances_code', 30)->unique()->nullable(); // Renamed from Grievance_Id
            $table->foreignId('order_id')->constrained('Orders_Placed_T')->onDelete('no action');
            $table->foreignId('orders_details_id')->constrained('Orders_Placed_Details_T')->onDelete('no action');

            $table->enum('issue_status', ['pending_review', 'resolved', 'cancelled'])->default('pending_review');
            $table->text('resolution_details')->nullable();
            $table->timestamps();
        });

        // Sales Returns Table
        Schema::create('Sales_Returns_T', function (Blueprint $table) {
            $table->id();
            $table->string('sales_return_code', 30)->unique()->nullable(); // Renamed from Sales_Return_Id
            $table->foreignId('order_id')->constrained('orders_placed_t')->onDelete('no action');
            $table->foreignId('orders_details_id')->constrained('Orders_Placed_Details_T')->onDelete('no action');
            $table->foreignId('product_id')->nullable()->constrained('Products_Master_T')->onDelete('no action');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'processed'])->default('pending');
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
            $table->string('defective_product_return_code', 30)->unique()->nullable(); // Renamed from Defective_Product_Return_Id
            $table->foreignId('sales_return_id')->constrained('Sales_Returns_T')->onDelete('no action');
            $table->text('defect_description');
            $table->text('resolution')->nullable();
            $table->timestamps();
        });

      //Feedback questions
      Schema::create('Orders_Feedbacks_Master_T', function (Blueprint $table) {
            $table->id();
            $table->string('feedback_question_code', 30)->unique()->nullable(); // Renamed from Feedback_Question_Id
            $table->string('question');
            $table->timestamps();
        });

        // Order Feedback Table
        Schema::create('Orders_Customers_Feedback_T', function (Blueprint $table) {
            $table->id();
            $table->string('Orders_Customers_Feedback_Code', 30)->unique()->nullable(); // Renamed from Feedback_Id
            $table->foreignId('orders_details_id')->constrained('Orders_Placed_Details_T')->onDelete('no action');

            $table->foreignId('customer_id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->foreignId('feedback_question_id')->constrained('Orders_Feedbacks_Master_T')->onDelete('no action');
            $table->boolean('response')->default(false);
            $table->timestamps();
        });

        // Loyalty Transactions Table
        Schema::create('Customers_Loyalty_Transactions_T', function (Blueprint $table) {
            $table->id();
            $table->string('loyalty_transaction_code', 30)->unique()->nullable(); // Renamed from Loyalty_Transaction_Id
            $table->foreignId('customer_id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->foreignId('order_id')->nullable()->constrained('Orders_Placed_T')->onDelete('set null');
            $table->integer('points_earned')->default(0);
            $table->integer('points_redeemed')->default(0);
            $table->timestamps();
        });


        Schema::create('Customers_Loyalty_T', function (Blueprint $table) {
            $table->id();
            $table->string('Customers_Loyalty_Code', 30)->unique()->nullable(); // Renamed from Loyalty_Id
            $table->foreignId('customer_id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->integer('points_earned')->default(0);
            $table->integer('points_redeemed')->default(0);
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
            $table->foreignId('customer_id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->string('company_name');
            $table->text('business_details');
            $table->string('region');
            $table->timestamps();
        });

        // Credit Customers Table
        Schema::create('Credit_Customers_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('Customers_Master_T')->onDelete('no action');
            $table->decimal('credit_limit', 10, 2);
            $table->decimal('balance_due', 10, 2)->default(0);
            $table->timestamps();
        });

        // Collaborative Projects Table
        Schema::create('Colx_Collaborative_Projects_T', function (Blueprint $table) {
            $table->id();
            $table->string('project_title');
            $table->text('description');
            $table->foreignId('customer_id')->nullable()->constrained('Customers_Master_T')->onDelete('set null');
            $table->timestamps();
        });

        // Freelancers Table
        Schema::create('Colx_Freelancers_T', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('Customers_Master_T')->onDelete('set null');
            $table->string('skillset');
            $table->text('experience');
            $table->timestamps();
        });

        // Internships Table
        Schema::create('Colx_Internships_T', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->foreignId('customer_id')->nullable()->constrained('Customers_Master_T')->onDelete('set null');
            $table->timestamps();
        });

        // Training Courses Table
        Schema::create('Colx_Training_Courses_T', function (Blueprint $table) {
            $table->id();
            $table->string('course_name');
            $table->text('description');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });

        // Job Positions Table
        Schema::create('Colx_Job_Positions_T', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
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
