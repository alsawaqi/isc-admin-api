<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConxNotificationDevice extends Model
{
    //
    protected $table = 'Conx_Notification_Devices_T';

    protected $fillable = [
        'user_id',
        'beams_device_id',
        'device_name',
        'browser_name',
        'browser_version',
        'os_name',
        'os_version',
        'user_agent',
        'ip_address',
        'is_enabled',
        'last_seen_at',
    ];

    protected $casts = [
        'is_enabled'  => 'boolean',
        'last_seen_at'=> 'datetime',
    ];

    public function user()
    {
        // adjust User::class and key if your User model/table is custom
        return $this->belongsTo(User::class, 'User_Id');
    }
}
