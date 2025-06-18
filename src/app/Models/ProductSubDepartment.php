<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSubDepartment extends Model
{
   protected $table = 'Products_Sub_Department_T';

    protected $guarded = [];

    public function productDepartment()
    {
        return $this->belongsTo(ProductDepartments::class, 'product_department_id');
    }

    public function subSubDepartments()
    {
        return $this->hasMany(ProductSubSubDepartment::class, 'product_sub_department_id', 'id');
    }


    public function department()
        {
            return $this->belongsTo(ProductDepartments::class, 'product_department_id', 'id');
        }
}
