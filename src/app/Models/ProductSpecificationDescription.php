<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationDescription extends Model
{
    protected $table = 'Product_Specification_Description_T';

    protected $guarded = [];

    public function values()
    {
        return $this->hasMany(ProductSpecificationProduct::class, 'product_specification_description_id');
    }

    public function subSubDepartment()
    {
        return $this->belongsTo(ProductSubSubDepartment::class, 'product_sub_sub_department_id');
    }


}
