<?php

namespace Database\Seeders;

 
use Illuminate\Database\Seeder;
use App\Models\SecurityPermission;

class SecurityPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $permissions = [
            'dashboard',
            'products',
            'product category',
            'departments',
            'sub departments',
            'sub sub departments',
            'addproductsdescription',
            'view products description',
            'product types',
            'product brands',
            'product manufacture',
            'product master',
            'product activation',
            'product stock',
            'product reports',
            'orders',
            'orders placed',
            'order packaging',
            'order dispatched',
            'order shipments',
            'order pickup',
            'order delivery',
            'order verification',
            'support tickets',
            'invoice',
            'invoice list',
            'invoice preview',
            'invoice add new',
            'other services',
            'free lancers',
            'collaborations',
            'admin',
            'users',
            'add new user',
            'view user profile',
            'print user profile',
            'define roles',
            'assign roles',
            'system parameters',
            'companies',
            'currencies',
            'merchant',
            'couriers',
            'admin report',
            'geography',
            'country',
            'state',
            'region',
            'districts',
            'city',
            'locations',
            'contact departments',
            'customer types',
            'customers',
            'shippingservices',
            'create shippers',
            'view shippers',
            'vendor services',
            'vendors',
            'vendor registration requests',
            'vendor users',
            'vendor requests',
            'vendor orders',
            'vendor payouts',
            'view products features',
            'support tickets',
           
           
        ];

        

        foreach ($permissions as $permission) {
            SecurityPermission::firstOrCreate(['name' => $permission]);
        }
    }
}
