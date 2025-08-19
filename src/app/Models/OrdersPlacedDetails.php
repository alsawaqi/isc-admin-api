<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersPlacedDetails extends Model
{
     protected $table = 'Orders_Placed_Details_T';

     public function orderPlaced()
     {
         return $this->belongsTo(OrdersPlaced::class, 'Orders_Placed_Id');
     }

     public function product()
     {
         return $this->belongsTo(ProductMaster::class, 'Products_Id');
     }
}
