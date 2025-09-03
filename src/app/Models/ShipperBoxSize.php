<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperBoxSize extends Model
{
    //

   protected $table = 'Shipper_Box_Sizes_T';

  protected $fillable = [
        'Shippers_Id',
        'Shippers_Box_Code',
        'Shippers_Box_Label',
        'Shippers_Box_Length_Cm',
        'Shippers_Box_Width_Cm',
        'Shippers_Box_Height_Cm',
        'Shippers_Box_Max_Weight_Kg',
        'Shippers_Box_Volume_Cbm',
        'Shippers_Box_Is_Active',
    ];

    protected $casts = [
        'Shippers_Box_Length_Cm'     => 'decimal:2',
        'Shippers_Box_Width_Cm'      => 'decimal:2',
        'Shippers_Box_Height_Cm'     => 'decimal:2',
        'Shippers_Box_Max_Weight_Kg' => 'decimal:3',
        'Shippers_Box_Volume_Cbm'    => 'decimal:6',
        'Shippers_Box_Is_Active'     => 'boolean',
    ];


    /** Relationships */
    public function shipper()
    {
        return $this->belongsTo(Shipper::class, 'Shippers_Id', 'id');
    }

    public function boxRates()
    {
        return $this->hasMany(ShipperBoxRate::class, 'Shippers_Box_Size_Id', 'id');
    }
}
