<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Title extends Model
{
    protected $table = 'Titles_Master_T';

    protected $guarded = [];

    protected $casts = [
        'Is_Active' => 'boolean',
    ];
}
