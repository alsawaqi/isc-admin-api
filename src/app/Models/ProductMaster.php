<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Cviebrock\EloquentSluggable\Sluggable;

class ProductMaster extends Model
{

    use Sluggable;

    protected $table = 'Products_Master_T';

    protected $guarded = [];



     public function sluggable(): array
    {
        return [
            'Slug' => [
                'source' => 'Product_Name'
            ]
        ];
    }

    public function specs(){
            return $this->hasMany(ProductSpecificationProduct::class, 'Product_Id');
        }
}
