<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationProduct extends Model
{

protected $table = 'Products_Specification_Product_T';

protected $gaurded = [];

public function header()
{
    return $this->belongsTo(ProductSpecificationDescription::class, 'product_specification_description_id');
}

public function product()
{
    return $this->belongsTo(ProductMaster::class, 'product_id');
}
}
