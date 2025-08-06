<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
            'products',
            'product category',
            'departments',
            'sub departments',
            'sub sub departments',
            'addproductsdescription',
            'product types',
            'product brands',
            'product manufacture',
            'product master',
            'product activation',
            'product reports',
            'orders',
            'orders placed',
            'order packaging',
            'order dispatched',
            'order shipments',
            'order delivery',
            'order verification',
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
            'city',
        ];

        foreach ($permissions as $permission) {
            SecurityPermission::firstOrCreate(['name' => $permission]);
        }
    }
}
