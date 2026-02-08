<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationTemp extends Model
{
    protected $table = 'Product_Specification_Product_Temp_T';

    protected $fillable = [
        'Product_Temporary_Id',
        'Product_Specification_Description_Id',
        'product_specification_value_id',
        'Created_By',
    ];

    // If table has created_at/updated_at, keep timestamps true (default).
    // If NOT, uncomment below:
    // public $timestamps = false;

    public function tempProduct()
    {
        return $this->belongsTo(ProductTemporary::class, 'Product_Temporary_Id');
    }

    public function description()
    {
        return $this->belongsTo(ProductSpecificationDescription::class, 'Product_Specification_Description_Id');
    }

    public function value()
    {
        return $this->belongsTo(ProductSpecificationValue::class, 'product_specification_value_id');
    }
}
