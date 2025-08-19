<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperVolumeRate extends Model
{
  protected $table = 'Shipper_Volume_Rates_T';

    protected $fillable = [
        'Shippers_Id',
        'Shippers_Destination_Id',
        'Shippers_Standard_Shipping_Volume_Size',
        'Shippers_Standard_Shipping_Volume_Rate',
        'Shippers_Currency',
        'Shippers_Min_Volume_Cbm',
        'Shippers_Max_Volume_Cbm',
        'Shippers_Base_Fee',
        'Shippers_Per_Cbm_Fee',
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
