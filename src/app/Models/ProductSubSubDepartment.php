<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Cviebrock\EloquentSluggable\Sluggable;

class ProductSubSubDepartment extends Model
{
    //

    use Sluggable;

    protected $table = 'Products_Sub_Sub_Department_T';

    protected $guarded = [];



    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name'
            ]
        ];
    }


    public function productSubDepartment(){
        return $this->belongsTo(ProductSubDepartment::class, 'product_sub_department_id');
    }


    public function subDepartment(){
        return $this->belongsTo(ProductSubDepartment::class, 'product_sub_department_id', 'id');
     }
}
