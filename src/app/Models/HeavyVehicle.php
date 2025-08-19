<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeavyVehicle extends Model
{
    protected $table = 'Heavy_Vehicles_T';

    protected $fillable = [
        'Shippers_Id',
        'Shippers_Vehicle_Type',
        'Shippers_Vehicle_Capacity_Ton',
    ];

    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }

    public function rates()
    {
        return $this->hasMany(ShipperHeavyRate::class, 'Shippers_Vehicle_Id', 'id');
    }
}
