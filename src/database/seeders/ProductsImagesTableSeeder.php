<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductsImagesTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('Products_Images_T')->insert([
            [
                
                'Product_Image_Code' => 'PIMG_2025_JUL_A_000001',
                'Products_Id' => 1,
                'Image_Path' => 'Products/NYAcM07Zy1CAUwRnSE1ZRpA4lQScc1Vti36nmToZ.jpg',
                'Image_Size' => 39357,
                'Image_Extension' => 'jpg',
                'Image_Type' => 'image/jpeg',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-15 09:11:21.843'),
                'updated_at' => Carbon::parse('2025-07-15 09:11:21.843'),
            ],
            [
                
                'Product_Image_Code' => 'PIMG_2025_JUL_A_000002',
                'Products_Id' => 2,
                'Image_Path' => 'Products/Z3IIt0HiIQSHR0q3pZqg4qfKEpPoSNcAT3v9FE7H.jpg',
                'Image_Size' => 9600,
                'Image_Extension' => 'jpg',
                'Image_Type' => 'image/jpeg',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 10:12:31.390'),
                'updated_at' => Carbon::parse('2025-07-17 10:12:31.390'),
            ],
            [
                
                'Product_Image_Code' => 'PIMG_2025_JUL_A_000003',
                'Products_Id' => 3,
                'Image_Path' => 'Products/BFBFJXRoOHS5oUyCAJn9RLRt3nrT8D36yQb1oHAx.jpg',
                'Image_Size' => 9968,
                'Image_Extension' => 'jpg',
                'Image_Type' => 'image/jpeg',
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-22 10:56:41.800'),
                'updated_at' => Carbon::parse('2025-07-22 10:56:41.800'),
            ],
        ]);
    }
}
