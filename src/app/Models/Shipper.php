<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipper extends Model
{
  protected $table = 'Shippers_Master_T';
    // PK is "id" because you used $table->id()
    protected $fillable = [
        'Shippers_Code','Shippers_Name','Shippers_Address','Shippers_Office_No',
        'Shippers_GSM_No','Shippers_Email_Address','Shippers_Official_Website_Address',
        'Shippers_GPS_Location','Shippers_Scope','Shippers_Type','Shippers_Rate_Mode',
        'Shippers_Is_Active','Shippers_Meta'
    ];

    protected $casts = [
        'Shippers_Is_Active' => 'boolean',
        'Shippers_Meta' => 'array',
    ];

    // Relationships
    public function contacts()
    {
        return $this->hasMany(ShipperContact::class, 'Shippers_Id', 'id');
    }

    public function destinations()
    {
        return $this->hasMany(ShipperDestination::class, 'Shippers_Id', 'id');
    }

    public function shippingRates()
    {
        return $this->hasMany(ShipperShippingRate::class, 'Shippers_Id', 'id');
    }

    public function volumeRates()
    {
        return $this->hasMany(ShipperVolumeRate::class, 'Shippers_Id', 'id');
    }

    public function weightRates()
    {
        return $this->hasMany(ShipperWeightRate::class, 'Shippers_Id', 'id');
    }

    public function heavyVehicles()
    {
        return $this->hasMany(HeavyVehicle::class, 'Shippers_Id', 'id');
    }

    public function heavyRates()
    {
        return $this->hasMany(ShipperHeavyRate::class, 'Shippers_Id', 'id');
    }
}
