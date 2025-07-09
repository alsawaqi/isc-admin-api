<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDepartments extends Model
{
    //
    protected $table = 'Products_Departments_T';

    protected $guarded = [];


   public function subDepartments(){
        return $this->hasMany(ProductSubDepartment::class, 'product_department_id', 'id');
    }
}
