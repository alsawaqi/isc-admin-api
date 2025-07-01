<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImages extends Model
{
    //

    protected $table = 'Products_Images_T';

    protected $fillable = [
        'product_image_code',
        'product_id',
        'image_path',
        'size',
        'extension',
        'type',
        'Created_By',
        'created_at',
        'updated_at'
    ];

}
