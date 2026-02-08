<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersPlacedVendors extends Model
{
    
    protected $table = 'Orders_Placed_Vendors_T';

    protected $fillable = [
        'Orders_Placed_Id',
        'Vendor_Id',
        'Vendor_Order_Code',
        'Sub_Total',
        'VAT',
        'Shipping',
        'Total',
        'Status',
        'Commission_Type',
        'Commission_Value',
        'Commission_Amount',
        'Payout_Status',
      
        // NEW
        'Payout_Amount',
        'Payout_At',
        'Payout_Reference',
    ];

    public function order()
    {
        return $this->belongsTo(OrdersPlaced::class, 'Orders_Placed_Id');
    }

    public function vendor()
    {
        return $this->belongsTo(VendorMaster::class, 'Vendor_Id');
    }
}
