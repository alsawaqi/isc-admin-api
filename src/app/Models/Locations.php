<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Locations extends Model
{
    protected $table = 'Geox_Location_Master_T';
    protected $guarded = [];



    public function city()
    {
        return $this->belongsTo(City::class, 'City_Id');
    }
}
