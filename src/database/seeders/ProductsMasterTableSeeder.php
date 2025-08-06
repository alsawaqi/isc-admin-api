<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
 

class ProductsMasterTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('Products_Master_T')->insert([
            [
                
                'Product_Code' => 'PROD_2025_JUL_A_000001',
                'Product_Department_Id' => 1,
                'Product_Sub_Department_Id' => 1,
                'Product_Sub_Sub_Department_Id' => 1,
                'Product_Type_Id' => 1,
                'Product_Brand_Id' => 2,
                'Product_Manufacture_Id' => 1,
                'Product_Name' => 'test',
                'Product_Name_Ar' => 'سيبسيب',
                'Product_Description' => 'sdfsdfdsf',
                'Product_Price' => 1000.00,
                'Product_Stock' => 1000,
                'Status' => 'available',
                'Minor_Item' => null,
                'Brand_Code' => null,
                'Manufacturer_Code' => null,
                'Product_User_Code' => null,
                'Barcode_Changed_Status' => null,
                'Inhouse_Barcode' => '1222112',
                'Inhouse_Barcode_Source' => null,
                'Product_Owner_Jurisdiction' => null,
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-15 09:11:20.110'),
                'updated_at' => Carbon::parse('2025-07-15 09:11:20.110'),
                'Slug' => 'test',
            ],
            [
                
                'Product_Code' => 'PROD_2025_JUL_A_000002',
                'Product_Department_Id' => 4,
                'Product_Sub_Department_Id' => 6,
                'Product_Sub_Sub_Department_Id' => 2,
                'Product_Type_Id' => 2,
                'Product_Brand_Id' => 3,
                'Product_Manufacture_Id' => 1,
                'Product_Name' => '1.5HP Async Motor - ABB',
                'Product_Name_Ar' => '213213',
                'Product_Description' => 'Electrical Async Motor',
                'Product_Price' => 15.00,
                'Product_Stock' => 20,
                'Status' => 'available',
                'Minor_Item' => null,
                'Brand_Code' => null,
                'Manufacturer_Code' => null,
                'Product_User_Code' => null,
                'Barcode_Changed_Status' => null,
                'Inhouse_Barcode' => '44444444',
                'Inhouse_Barcode_Source' => null,
                'Product_Owner_Jurisdiction' => null,
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-17 10:12:29.757'),
                'updated_at' => Carbon::parse('2025-07-17 10:12:29.757'),
                'Slug' => '1-5hp-async-motor-abb',
            ],
            [
                
                'Product_Code' => 'PROD_2025_JUL_A_000003',
                'Product_Department_Id' => 4,
                'Product_Sub_Department_Id' => 6,
                'Product_Sub_Sub_Department_Id' => 2,
                'Product_Type_Id' => 2,
                'Product_Brand_Id' => 5,
                'Product_Manufacture_Id' => 1,
                'Product_Name' => 'ABB Async Motor 0.75 KW',
                'Product_Name_Ar' => 'ASYNC',
                'Product_Description' => 'ABB Async Motor 0.75 KW 1450 RPM 220 V AC',
                'Product_Price' => 25.60,
                'Product_Stock' => 7,
                'Status' => 'available',
                'Minor_Item' => null,
                'Brand_Code' => null,
                'Manufacturer_Code' => null,
                'Product_User_Code' => null,
                'Barcode_Changed_Status' => null,
                'Inhouse_Barcode' => '123456',
                'Inhouse_Barcode_Source' => null,
                'Product_Owner_Jurisdiction' => null,
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-22 10:56:39.940'),
                'updated_at' => Carbon::parse('2025-07-22 10:56:39.940'),
                'Slug' => 'abb-async-motor-0-75-kw',
            ],
        ]);
    }
}