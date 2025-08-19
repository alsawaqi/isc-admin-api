<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    //
       protected $table = 'Geox_Region_Master_T';

       protected $guarded = [];

        public function country(){
         return $this->belongsTo(Country::class, 'Country_Id','id');
        }
}
