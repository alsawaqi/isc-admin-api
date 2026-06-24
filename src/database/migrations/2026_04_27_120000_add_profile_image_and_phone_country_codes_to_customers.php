<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Customers_Master_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Customers_Master_T', 'Telephone_Country_Code')) {
                $table->string('Telephone_Country_Code', 12)->nullable();
            }

            if (!Schema::hasColumn('Customers_Master_T', 'Customer_Profile_Image_Path')) {
                $table->string('Customer_Profile_Image_Path', 500)->nullable();
            }

            if (!Schema::hasColumn('Customers_Master_T', 'Customer_Profile_Image_Size')) {
                $table->integer('Customer_Profile_Image_Size')->nullable();
            }

            if (!Schema::hasColumn('Customers_Master_T', 'Customer_Profile_Image_Extension')) {
                $table->string('Customer_Profile_Image_Extension', 20)->nullable();
            }

            if (!Schema::hasColumn('Customers_Master_T', 'Customer_Profile_Image_Type')) {
                $table->string('Customer_Profile_Image_Type', 100)->nullable();
            }
        });

        Schema::table('Customers_Contact_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Customers_Contact_T', 'Telephone_Country_Code')) {
                $table->string('Telephone_Country_Code', 12)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('Customers_Contact_T', function (Blueprint $table) {
            if (Schema::hasColumn('Customers_Contact_T', 'Telephone_Country_Code')) {
                $table->dropColumn('Telephone_Country_Code');
            }
        });

        Schema::table('Customers_Master_T', function (Blueprint $table) {
            foreach ([
                'Customer_Profile_Image_Type',
                'Customer_Profile_Image_Extension',
                'Customer_Profile_Image_Size',
                'Customer_Profile_Image_Path',
                'Telephone_Country_Code',
            ] as $column) {
                if (Schema::hasColumn('Customers_Master_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
