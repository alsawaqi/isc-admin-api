<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationValue extends Model
{
    //

    protected $table = 'Product_Specification_Value_T';

    protected $guarded = [];

    public function description()
    {
        return $this->belongsTo(ProductSpecificationDescription::class, 'product_specification_description_id');
    }

    public function productSpecs()
    {
        return $this->hasMany(ProductSpecificationProduct::class, 'product_specification_value_id');
    }
}
