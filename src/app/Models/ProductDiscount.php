<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductDiscount extends Model
{
    use SoftDeletes;

    protected $table = 'Products_Discounts_T';

    protected $guarded = [];

    protected $casts = [
        'Product_Discount_Value' => 'float',
        'Product_Discount_Is_Active' => 'boolean',
        'Start_Date' => 'datetime',
        'End_Date' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'Products_Id', 'id');
    }

    public function department()
    {
        return $this->belongsTo(ProductDepartments::class, 'Product_Department_Id', 'id');
    }

    public function subDepartment()
    {
        return $this->belongsTo(ProductSubDepartment::class, 'Product_Sub_Department_Id', 'id');
    }

    public function subSubDepartment()
    {
        return $this->belongsTo(ProductSubSubDepartment::class, 'Product_Sub_Sub_Department_Id', 'id');
    }
}
