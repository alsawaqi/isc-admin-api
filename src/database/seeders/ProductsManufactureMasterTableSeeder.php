<?php

namespace Database\Seeders;

use Illuminate\Support\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductsManufactureMasterTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('Products_Manufacture_Master_T')->insert([
            [
                
                'Product_Manufacture_Code' => 'MFR_2025_JUL_A_000001',
                'Products_Manufacture_Name' => 'Manu',
                'created_at' => Carbon::parse('2025-07-15 09:10:32.797'),
                'updated_at' => Carbon::parse('2025-07-15 09:10:32.797'),
                
            ],
        ]);
    }
}