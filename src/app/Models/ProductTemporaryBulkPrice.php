<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quantity-tier bulk price row on a TEMP (vendor-submitted) product.
 * Copied to Products_Bulk_Prices_T when the temp product is approved.
 */
class ProductTemporaryBulkPrice extends Model
{
    protected $table = 'Products_Temporary_Bulk_Prices_T';

    protected $guarded = [];

    protected $casts = [
        'Products_Temporary_Id' => 'integer',
        'Min_Qty'               => 'integer',
        'Max_Qty'               => 'integer',
        'Unit_Price'            => 'float',
        'Created_By'            => 'integer',
    ];

    public function tempProduct()
    {
        return $this->belongsTo(ProductTemporary::class, 'Products_Temporary_Id');
    }
}
