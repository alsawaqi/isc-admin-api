<?php

namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use App\Models\SecurityRole;
use App\Models\SecurityPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{

    public function index(Request $request)
    {
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);
        $query = SecurityRole::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }



        if (!in_array($sortBy, ['id', 'name', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);


        $roles = $query->paginate($perPage);

        return response()->json($roles);
    }


    public function index_all()
    {
        return response()->json(
            SecurityRole::orderBy('id', 'DESC')->get()
        );
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function storeRole(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:Security_Roles_T,name',
            'permissions' => 'required|array',
            'permissions.*' => 'exists:Security_Permissions_T,name',
        ]);

        $guardName = SecurityPermission::where('name', 'departments')->value('guard_name')
            ?: config('auth.defaults.guard', 'web');
        $role = SecurityRole::create([
            'name' => $validated['name'],
            'guard_name' => $guardName,
        ]);

        $permissions = SecurityPermission::whereIn('name', $validated['permissions'])->pluck('id');

        $pivotData = $permissions->map(fn($id) => [
            'permission_id' => $id,
            'role_id' => $role->id,
        ])->toArray();

        DB::table('Security_Role_Has_Permissions_T')->insert($pivotData);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => 'Role and permissions created successfully',
            'role' => $role
        ], 201);
    }


    public function storePermission(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:Security_Permissions_T,name']);

        $guardName = SecurityPermission::where('name', 'departments')->value('guard_name')
            ?: config('auth.defaults.guard', 'web');
        $permission = SecurityPermission::create([
            'name' => $request->name,
            'guard_name' => $guardName,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return response()->json($permission, 201);
    }

    public function assignRole(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:Secx_Admin_User_Master_T,id',
            'role_id' => 'required|exists:Security_Roles_T,id',
        ]);

        $user = User::findOrFail($request->user_id);
        $role = SecurityRole::findOrFail($request->role_id);

        $existing = DB::table('Security_Model_Has_Roles_T')
            ->where('model_id', $user->id)
            ->where('model_type', \App\Models\User::class)
            ->first();

        if ($existing) {
            // Update existing role assignment
            DB::table('Security_Model_Has_Roles_T')
                ->where('model_id', $user->id)
                ->where('model_type', \App\Models\User::class)
                ->update(['role_id' => $role->id]);
        } else {
            // Insert new role assignment
            DB::table('Security_Model_Has_Roles_T')->insert([
                'role_id'    => $role->id,
                'model_type' => \App\Models\User::class,
                'model_id'   => $user->id,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Role assigned successfully'], 200);
    }



    public function getRolePermissions($id)
    {
        $role = SecurityRole::with('permissions')->findOrFail($id);

        return response()->json([
            'permissions' => $role->permissions->pluck('name') // Assuming 'name' is used in checkboxes
        ]);
    }


    public function updateRolePermissions(Request $request, $id)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:Security_Permissions_T,name',
        ]);

        $role = SecurityRole::findOrFail($id);
        $permissions = SecurityPermission::whereIn('name', $request->permissions)->get();

        DB::transaction(function () use ($role, $permissions) {
            DB::table('Security_Role_Has_Permissions_T')->where('role_id', $role->id)->delete();

            $insert = [];
            foreach ($permissions as $permission) {
                $insert[] = [
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                ];
            }

            DB::table('Security_Role_Has_Permissions_T')->insert($insert);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Permissions updated successfully']);
    }


    public function deleteRole($id)
    {
        $role = SecurityRole::findOrFail($id);
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Role deleted successfully']);
    }
}
