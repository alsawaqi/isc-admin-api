<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SecurityRole extends SpatieRole
{
    protected $table = 'Security_Roles_T';

    protected $fillable = ['name', 'guard_name'];

    // Must match the original Spatie definition
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            SecurityPermission::class,
            'Security_Role_Has_Permissions_T',
            'role_id',
            'permission_id'
        );
    }
}
