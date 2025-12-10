<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerFavoirtes extends Model
{
    //
    protected $table = 'Favorites_Master_T';


    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'Products_Id', 'id');
    }



     public function customer()
    {
        return $this->belongsTo(Customers::class, 'Customers_Id', 'id');
    }


    
}
