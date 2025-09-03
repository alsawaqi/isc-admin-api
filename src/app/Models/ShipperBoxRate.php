<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperBoxRate extends Model
{
      protected $table = 'Shipper_Box_Rates_T';

    protected $fillable = [
        'Shippers_Id',
        'Shippers_Destination_Id',
        'Shippers_Box_Size_Id',
        'Shippers_Flat_Box_Rate',
        'Shippers_Base_Fee',
        'Shippers_Currency',
        'Shippers_Max_Weight_Kg',
    ];

    protected $casts = [
        'Shippers_Flat_Box_Rate' => 'decimal:3',
        'Shippers_Base_Fee'      => 'decimal:3',
        'Shippers_Max_Weight_Kg' => 'decimal:3',
    ];

    /** Relationships */
    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }

    public function destination()
    {
        return $this->belongsTo(ShipperDestination::class, 'Shippers_Destination_Id', 'id');
    }

    public function boxSize()
    {
        return $this->belongsTo(ShipperBoxSize::class, 'Shippers_Box_Size_Id', 'id');
    }
}
