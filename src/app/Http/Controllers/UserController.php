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
    
    public function store(Request $request){


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
                'User_Id'=> $UserCode,
                'User_Name' => $request->User_Name,
                'email' => $validated['email'],
                'password' => Hash::make($request->password),
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



public function getUsersWithRoles()
{
    $users = User::with('roles')->get();
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
}
