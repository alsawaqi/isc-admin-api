<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperHeavyRate extends Model
{
    protected $table = 'Shipper_Heavy_Rates_T';

    protected $fillable = [
        'Shippers_Id',
        'Shippers_Destination_Id',
        'Shippers_Vehicle_Id',
        'Shippers_Flat_Rate',
        'Shippers_Hourly_Rate',
        'Shippers_Min_Hours',
        'Shippers_Currency',
    ];

    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }

    public function destination()
    {
        return $this->belongsTo(ShipperDestination::class, 'Shippers_Destination_Id', 'id');
    }

    public function vehicle()
    {
        return $this->belongsTo(HeavyVehicle::class, 'Shipers_Vehicle_Id', 'id');
    }
}
