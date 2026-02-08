<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductManufacture extends Model
{
    protected $table = 'Products_Manufacture_Master_T';
    protected $guarded = [];

    public function department()
    {
        return $this->belongsTo(ProductDepartments::class, 'Product_Department_Id', 'id');
    }
    
}
