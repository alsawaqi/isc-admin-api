<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductTemporaryImage extends Model
{
    use SoftDeletes;

    protected $table = 'Products_Temporary_Images_T';

    protected $fillable = [
        'Products_Temporary_Id',
        'Image_Path',
        'Image_Size',
        'Image_Extension',
        'Image_Type',
        'Is_Default',
        'Created_By',
    ];

    protected $casts = [
        'Products_Temporary_Id' => 'integer',
        'Image_Size' => 'integer',
        'Is_Default' => 'boolean',
    ];

    public function tempProduct()
    {
        return $this->belongsTo(ProductTemporary::class, 'Products_Temporary_Id');
    }
}
