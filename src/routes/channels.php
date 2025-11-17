<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('admin.notifications', function ($user) {
    // if you want auth:
    return $user !== null;
});
