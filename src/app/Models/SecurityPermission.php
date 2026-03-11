<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class SecurityPermission extends SpatiePermission
{
    protected $table = 'Security_Permissions_T';
}
