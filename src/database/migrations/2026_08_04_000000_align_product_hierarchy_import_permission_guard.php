<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'Security_Permissions_T',
            'Security_Role_Has_Permissions_T',
            'Security_Model_Has_Permissions_T',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Cannot align hierarchy import access because {$table} is missing.");
            }
        }

        $departmentPermission = DB::table('Security_Permissions_T')
            ->where('name', 'departments')
            ->first(['id', 'guard_name']);

        if (! $departmentPermission) {
            throw new RuntimeException('Cannot align hierarchy import access because the departments permission is missing.');
        }

        $guardName = trim((string) $departmentPermission->guard_name);
        if ($guardName === '') {
            $guardName = (string) config('auth.defaults.guard', 'web');
        }

        $importPermission = DB::table('Security_Permissions_T')
            ->where('name', 'import product categories')
            ->first(['id', 'guard_name']);

        if ($importPermission) {
            $importPermissionId = (int) $importPermission->id;
            if ((string) $importPermission->guard_name !== $guardName) {
                DB::table('Security_Permissions_T')->where('id', $importPermissionId)->update([
                    'guard_name' => $guardName,
                    'updated_at' => now(),
                ]);
            }
        } else {
            $importPermissionId = (int) DB::table('Security_Permissions_T')->insertGetId([
                'name' => 'import product categories',
                'guard_name' => $guardName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('Security_Role_Has_Permissions_T')
            ->where('permission_id', $departmentPermission->id)
            ->pluck('role_id') as $roleId) {
            DB::table('Security_Role_Has_Permissions_T')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $importPermissionId,
            ]);
        }

        foreach (DB::table('Security_Model_Has_Permissions_T')
            ->where('permission_id', $departmentPermission->id)
            ->get() as $assignment) {
            DB::table('Security_Model_Has_Permissions_T')->updateOrInsert([
                'permission_id' => $importPermissionId,
                'model_id' => $assignment->model_id,
                'model_type' => $assignment->model_type,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // This repairs authorization data. Restoring the known-bad guard would
        // lock administrators out again, so rollback intentionally keeps it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
