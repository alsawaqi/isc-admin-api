<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersPlacedDetailsAdjustment extends Model
{
    protected $table = 'Orders_Placed_Details_Adjustments_T';

    protected $guarded = [];

    protected $casts = [
        'Orders_Placed_Id' => 'integer',
        'Orders_Placed_Details_Id' => 'integer',
        'Orders_Placed_Vendor_Id' => 'integer',
        'Products_Id' => 'integer',
        'Vendor_Id' => 'integer',
        'Quantity' => 'integer',
        'Amount' => 'decimal:3',
        'Restock_Quantity' => 'integer',
        'Actor_User_Id' => 'integer',
        'Metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(OrdersPlaced::class, 'Orders_Placed_Id');
    }

    public function detail()
    {
        return $this->belongsTo(OrdersPlacedDetails::class, 'Orders_Placed_Details_Id');
    }

    public function vendorOrder()
    {
        return $this->belongsTo(OrdersPlacedVendors::class, 'Orders_Placed_Vendor_Id');
    }
}
