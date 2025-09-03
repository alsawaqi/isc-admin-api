<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperDestination extends Model
{
        protected $table = 'Shipper_Destinations_T';

    protected $fillable = [
        'Shippers_Id',
        'Shippers_Destination_Country',
        'Shippers_Destination_Region',
        'Shippers_Destination_District',
        'Shippers_Destination_Rate_Applicability',
        'Shippers_Destination_Country_Preference',
        'Shippers_Destination_Region_Preference',
        'Shippers_Destination_District_Preference',
    ];

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
}
