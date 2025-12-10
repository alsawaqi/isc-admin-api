<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCart extends Model
{
    //

    protected $table = 'Customers_Carts_T';
    protected $guarded = [];


    public function customer()
    {
        return $this->belongsTo(Customers::class, 'Customers_Id', 'id');
    }
    

    public function product(){
         return $this->belongsTo(ProductMaster::class, 'Products_Id', 'id');
    }

}
