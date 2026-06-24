<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Notifications\DatabaseNotification;

class ConxDatabaseNotification extends DatabaseNotification
{
    protected $table = 'Conx_Notifications_T';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    // MSSQL: force array -> JSON string
    public function setDataAttribute($value): void
    {
        $this->attributes['data'] = is_array($value)
            ? json_encode($value)
            : $value;
    }
}
