<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSubSubDepartment extends Model
{
    //

    protected $table = 'Products_Sub_Sub_Department_T';

    protected $guarded = [];


    public function productSubDepartment(){
        return $this->belongsTo(ProductSubDepartment::class, 'product_sub_department_id');
    }


    public function subDepartment(){
        return $this->belongsTo(ProductSubDepartment::class, 'product_sub_department_id', 'id');
     }
}
