<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipperVolumetricRule extends Model
{
    //

    protected $table = 'Shipper_Volumetric_Rules_T';

    protected $fillable = [
        'Shippers_Id',
        'Shippers_Destination_Id',
        'Enabled',
        'Divisor',
        'Max_L_cm',
        'Max_W_cm',
        'Max_H_cm',
        'Note',
    ];
}
