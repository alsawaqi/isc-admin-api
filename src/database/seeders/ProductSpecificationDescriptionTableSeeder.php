<?php

namespace Database\Seeders;

use Illuminate\Support\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductSpecificationDescriptionTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('Product_Specification_Description_T')->insert([
            [
                
                'Product_Specification_Description_Name' => 'test 1',
                'product_sub_sub_department_id' => 1,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-15 09:10:02.287'),
                'updated_at' => Carbon::parse('2025-07-15 09:10:02.287'),
            ],
            [
                
                'Product_Specification_Description_Name' => 'test 2',
                'product_sub_sub_department_id' => 1,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-15 09:10:02.287'),
                'updated_at' => Carbon::parse('2025-07-15 09:10:02.287'),
            ],
            [
                
                'Product_Specification_Description_Name' => 'RPM',
                'product_sub_sub_department_id' => 2,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 09:57:49.890'),
                'updated_at' => Carbon::parse('2025-07-17 09:57:49.890'),
            ],
            [
                
                'Product_Specification_Description_Name' => 'Frequency',
                'product_sub_sub_department_id' => 2,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 09:57:49.890'),
                'updated_at' => Carbon::parse('2025-07-17 09:57:49.890'),
            ],
            [
                
                'Product_Specification_Description_Name' => 'Voltage',
                'product_sub_sub_department_id' => 2,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 09:57:49.890'),
                'updated_at' => Carbon::parse('2025-07-17 09:57:49.893'),
            ],
            [
                
                'Product_Specification_Description_Name' => 'Phases',
                'product_sub_sub_department_id' => 2,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 09:57:49.893'),
                'updated_at' => Carbon::parse('2025-07-17 09:57:49.893'),
            ],
            [
                
                'Product_Specification_Description_Name' => 'Efficiency',
                'product_sub_sub_department_id' => 2,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 09:57:49.893'),
                'updated_at' => Carbon::parse('2025-07-17 09:57:49.893'),
            ],
            [
                
                'Product_Specification_Description_Name' => 'IP Protection',
                'product_sub_sub_department_id' => 2,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 09:57:49.893'),
                'updated_at' => Carbon::parse('2025-07-17 09:57:49.893'),
            ],
        ]);
    }
}