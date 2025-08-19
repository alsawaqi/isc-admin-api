<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersPlaced extends Model
{
    protected $table = 'Orders_Placed_T';
    protected $guarded = [];


    public function customerContact()
    {
        return $this->belongsTo(CustomerContact::class, 'Customers_Contacts_Id');
    }    


    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id');
    }

    public function orderlist()
    {
         return $this->hasMany(OrdersPlacedDetails::class, 'Orders_Placed_Id');
    }
}
