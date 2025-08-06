<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductsDepartmentsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('Products_Departments_T')->insert([
            [
                
                'Product_Department_Code' => 'DEPT_2025_JUL_A_000001',
                'Product_Department_Name' => 'test',
                'Product_Department_Name_Ar' => null,
                'Touch_Screen_Status' => null,
                'Stock_Control_Status' => null,
                'Image_Path' => 'department/ezxoIhhQoTQdhgJQf7tkikByy5POAIHkKKwS0vMX.jpg',
                'Image_Size' => 39357,
                'Image_Extension' => 'jpg',
                'Image_Type' => 'image/jpeg',
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-15 07:56:16.703'),
                'updated_at' => Carbon::parse('2025-07-15 07:56:16.703'),
            ],
            [
                
                'Product_Department_Code' => 'DEPT_2025_JUL_A_000002',
                'Product_Department_Name' => 'Hydraulics',
                'Product_Department_Name_Ar' => null,
                'Touch_Screen_Status' => null,
                'Stock_Control_Status' => null,
                'Image_Path' => 'department/vUpsruLizbq6YBfXZARRbrM1SC7iLJYJ9J302eor.jpg',
                'Image_Size' => 22343,
                'Image_Extension' => 'jpeg',
                'Image_Type' => 'image/jpeg',
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:17:45.077'),
                'updated_at' => Carbon::parse('2025-07-16 13:17:45.077'),
            ],
            [
                
                'Product_Department_Code' => 'DEPT_2025_JUL_A_000003',
                'Product_Department_Name' => 'Pneumatics',
                'Product_Department_Name_Ar' => null,
                'Touch_Screen_Status' => null,
                'Stock_Control_Status' => null,
                'Image_Path' => 'department/pHKIKTe5Mps6dfmLLfUbQkdENMJUBUiLiOeT50Z1.png',
                'Image_Size' => 87578,
                'Image_Extension' => 'png',
                'Image_Type' => 'image/png',
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:18:15.510'),
                'updated_at' => Carbon::parse('2025-07-16 13:18:15.510'),
            ],
            [
                
                'Product_Department_Code' => 'DEPT_2025_JUL_A_000004',
                'Product_Department_Name' => 'Motors',
                'Product_Department_Name_Ar' => null,
                'Touch_Screen_Status' => null,
                'Stock_Control_Status' => null,
                'Image_Path' => 'department/SYSpWznL2DZZbd8QeFGc9P3KEiExsVO2O4hAtwbD.png',
                'Image_Size' => 49157,
                'Image_Extension' => 'png',
                'Image_Type' => 'image/png',
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:18:36.523'),
                'updated_at' => Carbon::parse('2025-07-16 13:18:36.523'),
            ],
            [
                
                'Product_Department_Code' => 'DEPT_2025_JUL_A_000005',
                'Product_Department_Name' => 'Tools',
                'Product_Department_Name_Ar' => null,
                'Touch_Screen_Status' => null,
                'Stock_Control_Status' => null,
                'Image_Path' => 'department/3OFC4Qeyp4Z6QQ8WoAwgRXugMwf7M2kMHO23KAOG.jpg',
                'Image_Size' => 169057,
                'Image_Extension' => 'jpeg',
                'Image_Type' => 'image/jpeg',
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:18:55.213'),
                'updated_at' => Carbon::parse('2025-07-16 13:18:55.213'),
            ],
            [
                
                'Product_Department_Code' => 'DEPT_2025_JUL_A_000006',
                'Product_Department_Name' => 'Electrical Switchgear',
                'Product_Department_Name_Ar' => null,
                'Touch_Screen_Status' => null,
                'Stock_Control_Status' => null,
                'Image_Path' => 'department/ob6utvdEjNQHyjrZ8cgNDFAG217wvyCHDiyh64mi.jpg',
                'Image_Size' => 55705,
                'Image_Extension' => 'jpeg',
                'Image_Type' => 'image/jpeg',
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:20:03.350'),
                'updated_at' => Carbon::parse('2025-07-16 13:20:03.350'),
            ],
            [
                
                'Product_Department_Code' => 'DEPT_2025_JUL_A_000007',
                'Product_Department_Name' => 'Electrical Wires & Cables',
                'Product_Department_Name_Ar' => null,
                'Touch_Screen_Status' => null,
                'Stock_Control_Status' => null,
                'Image_Path' => 'department/AIFCrWq3XJHHRG0wcI76T7pcvQ30W5j5qoocH1x0.jpg',
                'Image_Size' => 116450,
                'Image_Extension' => 'jpeg',
                'Image_Type' => 'image/jpeg',
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:22:36.297'),
                'updated_at' => Carbon::parse('2025-07-16 13:22:36.297'),
            ],
            [
                
                'Product_Department_Code' => 'DEPT_2025_JUL_A_000008',
                'Product_Department_Name' => 'Bearings',
                'Product_Department_Name_Ar' => null,
                'Touch_Screen_Status' => null,
                'Stock_Control_Status' => null,
                'Image_Path' => 'department/pv4Ffow9utiFu9wJGjLACqwPyJJZ4aJObFZM53XX.jpg',
                'Image_Size' => 252777,
                'Image_Extension' => 'jpeg',
                'Image_Type' => 'image/jpeg',
                'Updated_Status' => null,
                'Created_By' => 1,
                'created_at' => Carbon::parse('2025-07-16 13:23:33.943'),
                'updated_at' => Carbon::parse('2025-07-16 13:23:33.943'),
            ],
        ]);
    }
}
