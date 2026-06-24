<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductQuestion extends Model
{
    use SoftDeletes;

    protected $table = 'Product_Questions_T';
    protected $guarded = [];

    protected $casts = [
        'Helpful_Count' => 'integer',
        'Report_Count' => 'integer',
        'Moderated_At' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'Products_Id');
    }

    public function customer()
    {
        return $this->belongsTo(Customers::class, 'Customers_Id');
    }

    public function answers()
    {
        return $this->hasMany(ProductQuestionAnswer::class, 'Product_Question_Id');
    }
}
