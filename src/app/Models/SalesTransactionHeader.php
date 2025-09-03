<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTransactionHeader extends Model
{
    //
    protected $table = 'Sales_Transaction_Header_T';

    protected $guarded = [];



      public function order()
    {
        return $this->belongsTo(OrdersPlaced::class, 'Order_Placed_Id', 'id');
    }



    public function details()
    {
        return $this->hasMany(SalesTransactionDetails::class, 'Sales_Transaction_Header_Id');
    }
    
}
