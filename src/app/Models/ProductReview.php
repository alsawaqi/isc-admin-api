<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductReview extends Model
{
    use SoftDeletes;

    protected $table = 'Product_Reviews_T';
    protected $guarded = [];

    protected $casts = [
        'Rating' => 'integer',
        'Verified_Purchase' => 'boolean',
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

    public function replies()
    {
        return $this->hasMany(ProductReviewReply::class, 'Product_Review_Id');
    }
}
