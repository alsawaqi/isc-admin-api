<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomersLoyalty extends Model
{
    //

    protected $table = 'Customers_Loyalty_T';

    protected $guarded = [];

    public function customer(){
        return $this->belongsTo(Customers::class,'Customers_Id','id');
    }
}
