<?php

namespace App\Permission;

use Spatie\Permission\PermissionRegistrar as BaseRegistrar;

class CustomPermissionRegistrar extends BaseRegistrar
{
    public function getRoleHasPermissionsTable(): string
    {
        return 'Security_Role_Has_Permissions_T';
    }

    public function getPermissionRoleTable(): string
    {
        return $this->getRoleHasPermissionsTable(); // Compatibility
    }
}
