<?php

namespace Database\Seeders;

use Illuminate\Support\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductsTypesMasterTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('Products_Types_Master_T')->insert([
            [
                
                'Product_Types_Code' => 'PRODTYPE_2025_JUL_A_000001',
                'Product_Types_Name' => 'type',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-15 09:10:10.070'),
                'updated_at' => Carbon::parse('2025-07-15 09:10:10.070'),
            ],
            [
                
                'Product_Types_Code' => 'PRODTYPE_2025_JUL_A_000002',
                'Product_Types_Name' => 'Electrical',
                'Created_By' => 1,

                'created_at' => Carbon::parse('2025-07-16 13:06:38.560'),
                'updated_at' => Carbon::parse('2025-07-16 13:06:38.560'),
            ],
            [
                
                'Product_Types_Code' => 'PRODTYPE_2025_JUL_A_000003',
                'Product_Types_Name' => 'Electronics',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:06:52.480'),
                'updated_at' => Carbon::parse('2025-07-16 13:06:52.480'),
            ],
            [
                
                'Product_Types_Code' => 'PRODTYPE_2025_JUL_A_000004',
                'Product_Types_Name' => 'Tools',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:07:06.943'),
                'updated_at' => Carbon::parse('2025-07-16 13:07:06.943'),
            ],
        ]);
    }
}
