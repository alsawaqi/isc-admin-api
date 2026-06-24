<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersPlacedDetails extends Model
{
     protected $table = 'Orders_Placed_Details_T';

     protected $fillable = [
         'Orders_Placed_Id',
         'Products_Id',
         'Quantity',
         'Price',
         'Sold_Amount',
         'Returned_Quantity',
         'Refunded_Amount',
         'Net_Amount',
         'Return_State',
         'Refund_State',
         'Last_Returned_At',
         'Last_Refunded_At',
         'Status',
     ];

     protected $casts = [
         'Quantity' => 'integer',
         'Returned_Quantity' => 'integer',
         'Price' => 'decimal:3',
         'Subtotal' => 'decimal:3',
         'Vat' => 'decimal:3',
         'Sold_Amount' => 'decimal:3',
         'Refunded_Amount' => 'decimal:3',
         'Net_Amount' => 'decimal:3',
         'Last_Returned_At' => 'datetime',
         'Last_Refunded_At' => 'datetime',
     ];

     public function orderPlaced()
     {
         return $this->belongsTo(OrdersPlaced::class, 'Orders_Placed_Id');
     }

     public function product()
     {
         return $this->belongsTo(ProductMaster::class, 'Products_Id');
     }

     public function adjustments()
     {
         return $this->hasMany(OrdersPlacedDetailsAdjustment::class, 'Orders_Placed_Details_Id');
     }

     public function vendorOrder()
     {
         return $this->belongsTo(OrdersPlacedVendors::class, 'Orders_Placed_Vendor_Id');
     }
}
