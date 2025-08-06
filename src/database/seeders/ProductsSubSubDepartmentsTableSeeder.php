<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProductsSubSubDepartmentsTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('Products_Sub_Sub_Department_T')->insert([
            [
                
                'Product_Sub_Sub_Department_Code' => 'SUBSUBDEPT_2025_JUL_A_000001',
                'Product_Sub_Department_Id' => 1,
                'Product_Sub_Sub_Department_Name' => 'test 3',
                'Product_Sub_Sub_Department_Name_Ar' => 'test 3',
                'Product_Sub_Sub_Department_Description' => 'test 3',
                'Image_Path' => 'subsubdepartment/sg7wLPrH2CU9xdgT9FFxGYLb4Quu9mwsBQmQjCdJ.jpg',
                'Image_Size' => 39357,
                'Image_Extension' => 'jpg',
                'Image_Type' => 'image/jpeg',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-15 09:09:33.723'),
                'updated_at' => Carbon::parse('2025-07-15 09:09:33.723'),
                'Slug' => 'test-3',
            ],
            [
                
                'Product_Sub_Sub_Department_Code' => 'SUBSUBDEPT_2025_JUL_A_000002',
                'Product_Sub_Department_Id' => 6,
                'Product_Sub_Sub_Department_Name' => 'Standard Motors',
                'Product_Sub_Sub_Department_Name_Ar' => 'Standard Motors',
                'Product_Sub_Sub_Department_Description' => null,
                'Image_Path' => 'subsubdepartment/k82pZmKnrrSd0HpkG7g71zyf5KAPdgBFpwIIeWGu.jpg',
                'Image_Size' => 9600,
                'Image_Extension' => 'jpg',
                'Image_Type' => 'image/jpeg',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 09:53:56.827'),
                'updated_at' => Carbon::parse('2025-07-17 09:53:56.827'),
                'Slug' => 'standard-motors',
            ],
            [
                
                'Product_Sub_Sub_Department_Code' => 'SUBSUBDEPT_2025_JUL_A_000003',
                'Product_Sub_Department_Id' => 6,
                'Product_Sub_Sub_Department_Name' => 'Async Explosion Proof Motors',
                'Product_Sub_Sub_Department_Name_Ar' => 'Async Explosion Proof Motors',
                'Product_Sub_Sub_Department_Description' => null,
                'Image_Path' => 'subsubdepartment/yNJb9r0bWWVNg1b0SPuXAGazbIOV9y2AP4J5tLBA.jpg',
                'Image_Size' => 7080,
                'Image_Extension' => 'jpg',
                'Image_Type' => 'image/jpeg',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 09:54:29.107'),
                'updated_at' => Carbon::parse('2025-07-17 09:54:29.107'),
                'Slug' => 'async-explosion-proof-motors',
            ],
            [
                
                'Product_Sub_Sub_Department_Code' => 'SUBSUBDEPT_2025_JUL_A_000004',
                'Product_Sub_Department_Id' => 19,
                'Product_Sub_Sub_Department_Name' => 'Spanners Singles',
                'Product_Sub_Sub_Department_Name_Ar' => 'Spanners Singles',
                'Product_Sub_Sub_Department_Description' => 'Spanners Individual Pieces',
                'Image_Path' => 'subsubdepartment/rQQgrp8wxPBGrPAP6d763lwwcwT0BdIfn6P10IFy.jpg',
                'Image_Size' => 2232,
                'Image_Extension' => 'jpg',
                'Image_Type' => 'image/jpeg',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-19 21:02:53.393'),
                'updated_at' => Carbon::parse('2025-07-19 21:02:53.393'),
                'Slug' => 'spanners-singles',
            ],
        ]);
    }
}