<?php

namespace App\Http\Controllers;


use App\Models\User;
use App\Models\SecurityRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{


    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('Email', $credentials['email'])->first();

        // Invalid credentials
        if (! $user || ! Hash::check($credentials['password'], $user->Password)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        // 🚫 Block check (No_Login numeric)
        $isBlockedByNoLogin = isset($user->No_Login) && (float) $user->No_Login > 0;

        if ($isBlockedByNoLogin) {
            return response()->json([
                'message' => 'Your account has been blocked. Please contact the administrator.',
            ], 403);
        }

        // Login OK
        $token = $user->createToken('user-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => $user,
        ]);
    }


    public function store(Request $request)
    {


        $UserCode = CodeGenerator::createCode('USR', 'Secx_Admin_User_Master_T', 'User_Id');


        $validated = $request->validate([
            'User_Name' => 'required|string|max:255',
            'email' => 'required|email|unique:Secx_Admin_User_Master_T,email',
            // 'password' => 'required|string|min:6|confirmed',

        ]);

        try {
            // Check if the user already exists
            if (User::where('email', $validated['email'])->exists()) {
                return response()->json(['message' => 'User already exists'], 409);
            }


            $user = User::create([
                'User_Id' => $UserCode,
                'User_Name' => $request->User_Name,
                'Email' => $validated['email'],
                'Password' => Hash::make($request->password),
            ]);

            $roleId = SecurityRole::where('name', $request->role)->value('id');

            if (!$roleId) {
                return response()->json(['error' => 'Invalid role provided.'], 400);
            }

            DB::table('Security_Model_Has_Roles_T')->insert([
                'role_id'    => $roleId,
                'model_type' => \App\Models\User::class,
                'model_id'   => $user->id,
            ]);
            return response()->json(['message' => 'User created successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error checking user existence: ' . $e->getMessage()], 500);
        }
    }



    public function getUsersWithRoles(Request $request)
    {


        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);

        $query = User::query();

        $query->with('roles');

        if ($search) {
            $query->where('User_Name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        }


        if (! in_array($sortBy, ['id', 'User_Name', 'created_at'])) {
            $sortBy = 'id';
        }


        $query->orderBy($sortBy, $sortDir);


        $users = $query->paginate($perPage);
        return response()->json($users);
    }

    public function assignRole(Request $request, User $user)
    {
        $request->validate([
            'role' => 'required|exists:Security_Roles_T,name'
        ]);

        $user->syncRoles([$request->role]);

        return response()->json(['message' => 'Role updated successfully']);
    }


    public function block($id)
    {
        $user = User::findOrFail($id);

        $user->No_Login = 1;

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User has been blocked',
            'user'    => $user,
        ]);
    }

    public function unblock($id)
    {
        $user = User::findOrFail($id);

        $user->No_Login = 0;

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User has been unblocked',
            'user'    => $user,
        ]);
    }


    public function changePassword(Request $request, $id)
    {
        // Optional: only allow admins
        // $this->authorize('update', User::class);

        $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::findOrFail($id);

        // Update hashed password (DB column is "Password")
        $user->Password = Hash::make($request->password);

        // If you REALLY need to track plain text (not recommended):
        // $user->Login_Password = $request->password;

        $user->save();

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }
}
