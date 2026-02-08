<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSubDepartment extends Model
{
   protected $table = 'Products_Sub_Department_T';

    protected $guarded = [];

    public function productDepartment()
    {
        return $this->belongsTo(ProductDepartments::class, 'Products_Departments_Id');
    }

    public function subSubDepartments()
    {
        return $this->hasMany(ProductSubSubDepartment::class, 'Product_Sub_Department_Id', 'id');
    }


    public function department()
        {
            return $this->belongsTo(ProductDepartments::class, 'Products_Departments_Id', 'id');
        }
}
