<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductsBrandsMasterTableSeeder extends Seeder
{
    public function run()
    {
     
        DB::table('Products_Brands_Master_T')->insert([
            [
                
                'Product_Brand_Code' => 'BRND_2025_JUL_A_000001',
                'Products_Brands_Name' => 'test',
                'Products_Brands_Name_Ar' => 'test',
                'created_at' => Carbon::parse('2025-07-14 20:14:55.753'),
                'updated_at' => Carbon::parse('2025-07-14 20:14:55.753'),
                'Brands_Image_Path' => 'brands/T3WcOVcICSy3Ayyzfyxv3AqZCE7sVptTCTPA9gyF.jpg',
                'Brands_Size' => 39357,
                'Brands_Extension' => 'jpg',
                'Brands_Type' => 'image/jpeg',
                'Created_By'=> 1,
                'created_at' => now(),
  'updated_at' => now(),
            ],
            [
                
                'Product_Brand_Code' => 'BRND_2025_JUL_A_000002',
                'Products_Brands_Name' => 'brands',
                'Products_Brands_Name_Ar' => 'brands',
                'created_at' => Carbon::parse('2025-07-15 09:10:18.700'),
                'updated_at' => Carbon::parse('2025-07-15 09:10:18.700'),
                'Brands_Image_Path' => null,
                'Brands_Size' => null,
                'Brands_Extension' => null,
                'Brands_Type' => null,
                'Created_By'=> 1,
                'created_at' => now(),
  'updated_at' => now(),
            ],
            [
                
                'Product_Brand_Code' => 'BRND_2025_JUL_A_000003',
                'Products_Brands_Name' => 'Bosch',
                'Products_Brands_Name_Ar' => 'Bosch',
                'created_at' => Carbon::parse('2025-07-16 13:08:12.147'),
                'updated_at' => Carbon::parse('2025-07-16 13:08:12.147'),
                'Brands_Image_Path' => null,
                'Brands_Size' => null,
                'Brands_Extension' => null,
                'Brands_Type' => null,
                'Created_By'=> 1,
                'created_at' => now(),
  'updated_at' => now(),
            ],
            [
                
                'Product_Brand_Code' => 'BRND_2025_JUL_A_000004',
                'Products_Brands_Name' => 'Makita',
                'Products_Brands_Name_Ar' => 'Makita',
                'created_at' => Carbon::parse('2025-07-16 13:08:21.223'),
                'updated_at' => Carbon::parse('2025-07-16 13:08:21.223'),
                'Brands_Image_Path' => null,
                'Brands_Size' => null,
                'Brands_Extension' => null,
                'Brands_Type' => null,
                'Created_By'=> 1,
                'created_at' => now(),
  'updated_at' => now(),
            ],
            [
                
                'Product_Brand_Code' => 'BRND_2025_JUL_A_000005',
                'Products_Brands_Name' => 'ABB',
                'Products_Brands_Name_Ar' => 'ABB',
                'created_at' => Carbon::parse('2025-07-22 10:52:22.263'),
                'updated_at' => Carbon::parse('2025-07-22 10:52:22.263'),
                'Brands_Image_Path' => 'brands/S4mYsIhYDD9b9JY9GbKv8apYONyRFRK1cHPmnxWR.jpg',
                'Brands_Size' => 40144,
                'Brands_Extension' => 'jpg',
                'Brands_Type' => 'image/jpeg',
                'Created_By'=> 1,
                'created_at' => now(),
  'updated_at' => now(),
            ],
        ]);
    }
}
