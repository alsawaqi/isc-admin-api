<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quantity-tier bulk price row on a MASTER product.
 * Min_Qty..Max_Qty inclusive; Max_Qty NULL = open-ended ("and above").
 */
class ProductBulkPrice extends Model
{
    protected $table = 'Products_Bulk_Prices_T';

    protected $guarded = [];

    protected $casts = [
        'Products_Id' => 'integer',
        'Min_Qty'     => 'integer',
        'Max_Qty'     => 'integer',
        'Unit_Price'  => 'float',
        'Created_By'  => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'Products_Id');
    }
}
