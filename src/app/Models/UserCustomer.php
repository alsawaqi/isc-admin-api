<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCustomer extends Model
{
    protected $table = 'Secx_User_Master_T';

    protected $guarded = [];

    public function customer()
    {
        return $this->hasOne(Customers::class, 'User_Id', 'id');
    }
}
