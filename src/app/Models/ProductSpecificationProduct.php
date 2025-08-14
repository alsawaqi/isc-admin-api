<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationProduct extends Model
{

protected $table = 'Product_Specification_Product_T';

protected $guarded = [];

    public function header()
    {
        return $this->belongsTo(ProductSpecificationDescription::class, 'Product_Specification_Description_Id');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'Product_Id');
    }

    public function description()
    {
        return $this->belongsTo(ProductSpecificationDescription::class, 'Product_Specification_Description_Id');
    }

    public function value()
    {
        return $this->belongsTo(ProductSpecificationValue::class, 'Product_Specification_Value_Id');
    }


    

    
}
