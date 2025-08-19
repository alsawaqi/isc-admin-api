<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperShippingRate extends Model
{
     protected $table = 'Shipper_Shipping_Rates_T';

    protected $fillable = [
        'Shippers_Id',
        'Shippers_Destination_Id',
        'Shippers_Destination_Country_Id',
        'Shippers_Destination_Region_Id',
        'Shippers_Destination_District_Id',
        'Shippers_Destination_Rate_Volume',
        'Shippers_Destination_Rate_Weight',
        'Shippers_Destination_Rate_Applicable',
    ];

    protected $casts = [
        'Shippers_Destination_Rate_Volume'    => 'boolean',
        'Shippers_Destination_Rate_Weight'    => 'boolean',
        'Shippers_Destination_Rate_Applicable'=> 'boolean',
    ];

    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }

    public function destination()
    {
        return $this->belongsTo(ShipperDestination::class, 'Shippers_Destination_Id', 'id');
    }

    // Optional normalized links (only if you use those FKs)
    public function country()
    {
        return $this->belongsTo(Country::class, 'Shippers_Destination_Country_Id', 'Country_Id');
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'Shippers_Destination_Region_Id', 'Region_Id');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'Shippers_Destination_District_Id', 'District_Id');
    }
}
