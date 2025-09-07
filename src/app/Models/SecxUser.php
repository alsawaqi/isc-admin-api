<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecxUser extends Model
{
    //
    protected $table = 'Secx_User_Master_T';


    public function customers(){
         return $this->hasMany(Customers::class,'User_Id');
    }
}
