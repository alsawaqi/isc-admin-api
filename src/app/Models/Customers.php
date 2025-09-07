<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customers extends Model
{
   protected $table = 'Customers_Master_T';

    public function users(){
         return $this->belongsTo(SecxUser::class,'User_Id');
    }
}
