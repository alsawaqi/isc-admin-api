<?php
return [


    'defaults' => [
    'guard_name' => 'sanctum', // Change from 'web' to 'sanctum'
],

    'models' => [
        'permission' => App\Models\SecurityPermission::class,
        'role' => App\Models\SecurityRole::class,
    ],

    'table_names' => [
        'roles' => 'Security_Roles_T',
        'permissions' => 'Security_Permissions_T',
        'model_has_permissions' => 'Security_Model_Has_Permissions_T', // ← if renamed
        'model_has_roles' => 'Security_Model_Has_Roles_T',   
        'role_has_permissions' => 'Security_Role_Has_Permissions_T',
    ],


];
