<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Designation extends Model
{
    protected $table = 'Designations_Master_T';

    protected $guarded = [];

    protected $casts = [
        'Is_Active' => 'boolean',
    ];
}
