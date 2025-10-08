<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperDestination extends Model
{
    protected $table = 'Shipper_Destinations_T';

    protected $guarded = [];

    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }

    public function shippingRates()
    {
        return $this->hasMany(ShipperShippingRate::class, 'Shippers_Destination_Id', 'id');
    }

    public function volumeRates()
    {
        return $this->hasMany(ShipperVolumeRate::class, 'Shippers_Destination_Id', 'id');
    }

    public function weightRates()
    {
        return $this->hasMany(ShipperWeightRate::class, 'Shippers_Destination_Id', 'id');
    }

    public function heavyRates()
    {
        return $this->hasMany(ShipperHeavyRate::class, 'Shippers_Destination_Id', 'id');
    }

    public function flags()
    {
        return $this->hasOne(ShipperShippingRate::class, 'Shippers_Destination_Id', 'id')
            ->where('Shippers_Id', $this->Shippers_Id);
    }

    /** NEW: box rates available for this destination */
    public function boxRates()
    {
        return $this->hasMany(ShipperBoxRate::class, 'Shippers_Destination_Id', 'id');
    }


    public function volumetricRule()
    {
        return $this->hasOne(ShipperVolumetricRule::class, 'Shippers_Destination_Id', 'id')
            ->where('Shippers_Id', $this->Shippers_Id);
    }
}
