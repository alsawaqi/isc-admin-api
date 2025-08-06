<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductSpecificationProductTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('Product_Specification_Product_T')->insert([
            ['Product_Id' => 1, 'Product_Specification_Description_Id' => 2, 'value' => 'color', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-15 09:11:20.117'), 'updated_at' => Carbon::parse('2025-07-15 09:11:20.117')],
            ['Product_Id' => 1, 'Product_Specification_Description_Id' => 1, 'value' => 'white', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-15 09:11:20.120'), 'updated_at' => Carbon::parse('2025-07-15 09:11:20.120')],
            ['Product_Id' => 2, 'Product_Specification_Description_Id' => 8, 'value' => 'IP65', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-17 10:12:29.767'), 'updated_at' => Carbon::parse('2025-07-17 10:12:29.767')],
            ['Product_Id' => 2, 'Product_Specification_Description_Id' => 7, 'value' => '90%', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-17 10:12:29.770'), 'updated_at' => Carbon::parse('2025-07-17 10:12:29.770')],
            ['Product_Id' => 2, 'Product_Specification_Description_Id' => 6, 'value' => '1 Ph', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-17 10:12:29.770'), 'updated_at' => Carbon::parse('2025-07-17 10:12:29.770')],
            ['Product_Id' => 2, 'Product_Specification_Description_Id' => 5, 'value' => '220 V AC', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-17 10:12:29.770'), 'updated_at' => Carbon::parse('2025-07-17 10:12:29.770')],
            ['Product_Id' => 2, 'Product_Specification_Description_Id' => 4, 'value' => '50 Hz', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-17 10:12:29.773'), 'updated_at' => Carbon::parse('2025-07-17 10:12:29.773')],
            ['Product_Id' => 2, 'Product_Specification_Description_Id' => 3, 'value' => '1440', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-17 10:12:29.773'), 'updated_at' => Carbon::parse('2025-07-17 10:12:29.773')],
            ['Product_Id' => 3, 'Product_Specification_Description_Id' => 8, 'value' => 'IP 65', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-22 10:56:39.953'), 'updated_at' => Carbon::parse('2025-07-22 10:56:39.953')],
            [ 'Product_Id' => 3, 'Product_Specification_Description_Id' => 7, 'value' => '90%', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-22 10:56:39.957'), 'updated_at' => Carbon::parse('2025-07-22 10:56:39.957')],
            [ 'Product_Id' => 3, 'Product_Specification_Description_Id' => 6, 'value' => '3', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-22 10:56:39.960'), 'updated_at' => Carbon::parse('2025-07-22 10:56:39.960')],
            [ 'Product_Id' => 3, 'Product_Specification_Description_Id' => 5, 'value' => '220', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-22 10:56:39.960'), 'updated_at' => Carbon::parse('2025-07-22 10:56:39.960')],
            [ 'Product_Id' => 3, 'Product_Specification_Description_Id' => 4, 'value' => '50 Hz', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-22 10:56:39.960'), 'updated_at' => Carbon::parse('2025-07-22 10:56:39.960')],
            [ 'Product_Id' => 3, 'Product_Specification_Description_Id' => 3, 'value' => '1450', 'Created_By'=>1,'created_at' => Carbon::parse('2025-07-22 10:56:39.963'), 'updated_at' => Carbon::parse('2025-07-22 10:56:39.963')],
        ]);
    }
}
