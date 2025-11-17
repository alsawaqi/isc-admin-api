<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;

class ConxDatabaseNotification extends DatabaseNotification
{
    protected $table = 'Conx_Notifications_T';

    protected $guarded = [];

    // MSSQL: force array -> JSON string
    public function setDataAttribute($value): void
    {
        $this->attributes['data'] = is_array($value)
            ? json_encode($value)
            : $value;
    }
}
