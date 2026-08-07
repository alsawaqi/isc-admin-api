<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDepartments extends Model
{
    protected $table = 'Products_Departments_T';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'Display_Order' => 'integer',
        ];
    }

    public function scopeDisplayOrdered($query)
    {
        return $query->orderBy('Display_Order')->orderBy('id');
    }

    public function subDepartments()
    {
        return $this->hasMany(ProductSubDepartment::class, 'Products_Departments_Id', 'id');
    }
}
