<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    //

    protected $table = 'Geox_City_Master_T';

    protected $guarded = [];

    public function state()
    {
        return $this->belongsTo(State::class, 'State_Id');
    }
}
