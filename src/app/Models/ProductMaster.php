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
            'slug' => [
                'source' => 'name'
            ]
        ];
    }
}
