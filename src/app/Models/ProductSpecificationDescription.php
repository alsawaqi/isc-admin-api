<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationDescription extends Model
{
    protected $table = 'Product_Specification_Description_T';

    protected $guarded = [];


     protected $casts = [
        'options_json' => 'array',   // <-- this makes Laravel JSON-encode/decode
        'is_required'  => 'boolean',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    

    public function values()
    {
        return $this->hasMany(ProductSpecificationValue::class, 'product_specification_description_id');
    }

    public function productSpecs() // rows in Product_Specification_Product_T
    {
        return $this->hasMany(ProductSpecificationProduct::class, 'Product_Specification_Description_Id');
    }

    public function subSubDepartment()
    {
        return $this->belongsTo(ProductSubSubDepartment::class, 'product_sub_sub_department_id');
    }


}
