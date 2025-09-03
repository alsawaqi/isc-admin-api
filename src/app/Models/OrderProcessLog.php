<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProcessLog extends Model
{
    //

    protected $table ='Order_Process_Log_T';

    protected $guarded = [];


     public function order() { return $this->belongsTo(OrdersPlaced::class, 'Orders_Placed_Id'); }
    public function user()  { return $this->belongsTo(User::class, 'Actor_User_Id'); }

    public function actor()
    {
        return $this->belongsTo(User::class, 'Actor_User_Id');
    }
}
