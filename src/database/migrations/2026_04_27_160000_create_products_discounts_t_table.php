<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Products_Discounts_T')) {
            Schema::create('Products_Discounts_T', function (Blueprint $table) {
                $table->id();
                $table->string('Product_Discount_Code', 50)->unique()->nullable();
                $table->string('Product_Discount_Name', 255);
                $table->string('Target_Type', 40);
                $table->unsignedBigInteger('Products_Id')->nullable();
                $table->unsignedBigInteger('Product_Department_Id')->nullable();
                $table->unsignedBigInteger('Product_Sub_Department_Id')->nullable();
                $table->unsignedBigInteger('Product_Sub_Sub_Department_Id')->nullable();
                $table->string('Product_Discount_Type', 40);
                $table->decimal('Product_Discount_Value', 12, 3);
                $table->dateTime('Start_Date')->nullable();
                $table->dateTime('End_Date')->nullable();
                $table->boolean('Product_Discount_Is_Active')->default(true);
                $table->unsignedBigInteger('Created_By')->nullable();
                $table->unsignedBigInteger('Updated_By')->nullable();
                $table->dateTime('Created_Date')->nullable();
                $table->dateTime('Updated_Date')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['Product_Discount_Is_Active', 'Start_Date', 'End_Date'], 'idx_prod_disc_active_dates');
                $table->index(['Target_Type', 'Products_Id'], 'idx_prod_disc_target_product');
                $table->index(['Target_Type', 'Product_Department_Id'], 'idx_prod_disc_target_dept');
                $table->index(['Target_Type', 'Product_Sub_Department_Id'], 'idx_prod_disc_target_subdept');
                $table->index(['Target_Type', 'Product_Sub_Sub_Department_Id'], 'idx_prod_disc_target_subsub');
            });
        }

        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Orders_Placed_T', 'Original_Sub_Total_Price')) {
                $table->decimal('Original_Sub_Total_Price', 12, 3)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_T', 'Product_Discount_Amount')) {
                $table->decimal('Product_Discount_Amount', 12, 3)->default(0);
            }
        });

        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Original_Unit_Price')) {
                $table->decimal('Original_Unit_Price', 12, 3)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Discounted_Unit_Price')) {
                $table->decimal('Discounted_Unit_Price', 12, 3)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Product_Discount_Id')) {
                $table->unsignedBigInteger('Product_Discount_Id')->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Product_Discount_Code')) {
                $table->string('Product_Discount_Code', 50)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Product_Discount_Name')) {
                $table->string('Product_Discount_Name', 255)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Product_Discount_Type')) {
                $table->string('Product_Discount_Type', 40)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Product_Discount_Value')) {
                $table->decimal('Product_Discount_Value', 12, 3)->nullable();
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Product_Discount_Amount')) {
                $table->decimal('Product_Discount_Amount', 12, 3)->default(0);
            }

            if (!Schema::hasColumn('Orders_Placed_Details_T', 'Line_Discount_Amount')) {
                $table->decimal('Line_Discount_Amount', 12, 3)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('Orders_Placed_Details_T', function (Blueprint $table) {
            $columns = [
                'Original_Unit_Price',
                'Discounted_Unit_Price',
                'Product_Discount_Id',
                'Product_Discount_Code',
                'Product_Discount_Name',
                'Product_Discount_Type',
                'Product_Discount_Value',
                'Product_Discount_Amount',
                'Line_Discount_Amount',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('Orders_Placed_Details_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('Orders_Placed_T', function (Blueprint $table) {
            foreach (['Original_Sub_Total_Price', 'Product_Discount_Amount'] as $column) {
                if (Schema::hasColumn('Orders_Placed_T', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('Products_Discounts_T');
    }
};
