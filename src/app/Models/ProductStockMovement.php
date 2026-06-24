<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStockMovement extends Model
{
    protected $table = 'Product_Stock_Movements_T';

    protected $guarded = [];

    protected $casts = [
        'Products_Id' => 'integer',
        'Vendor_Id' => 'integer',
        'Quantity_Delta' => 'integer',
        'Quantity' => 'integer',
        'Previous_Stock' => 'integer',
        'New_Stock' => 'integer',
        'Actor_Id' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'Products_Id', 'id');
    }
}
