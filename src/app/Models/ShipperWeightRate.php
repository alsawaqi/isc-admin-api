<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperWeightRate extends Model
{
     protected $table = 'Shipper_Weight_Rates_T';

    protected $fillable = [
        'Shippers_Id',
        'Shippers_Destination_Id',
        'Shippers_Standard_Shipping_Weight_Size',
        'Shippers_Standard_Shipping_Weight_Rate',
        'Shippers_Currency',
        'Shippers_Min_Weight_Kg',
        'Shippers_Max_Weight_Kg',
        'Shippers_Base_Fee',
        'Shippers_Per_Kg_Fee',
        'Shippers_Flat_Fee',
    ];

    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }

    public function destination()
    {
        return $this->belongsTo(ShipperDestination::class, 'Shippers_Destination_Id', 'id');
    }
}
