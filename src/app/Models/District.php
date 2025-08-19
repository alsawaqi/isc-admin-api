<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    protected $table = 'Geox_District_Master_T';
    protected $guarded = [];

    public function region()
    {
        return $this->belongsTo(Region::class, 'Region_Id','id');
    }
}
