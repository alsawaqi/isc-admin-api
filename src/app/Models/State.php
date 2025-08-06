<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    protected $table = 'Geox_State_Master_T';

    protected $guarded = [];

    public function country(){
         return $this->belongsTo(Country::class, 'Country_Id');
   }

}
