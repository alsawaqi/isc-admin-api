<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerCart extends Model
{
    use SoftDeletes;

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
