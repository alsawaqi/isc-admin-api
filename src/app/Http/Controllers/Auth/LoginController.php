<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
   public function store(Request $request)
    {
        // Validate input
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Find user by email
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Create API token (Sanctum or Passport)

         Auth::login($user);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

   public function logout(Request $request)
{
    $user = $request->user();

    if ($user && $user->currentAccessToken()) {
        // Only delete if it's a stored token
        if (! $user->currentAccessToken() instanceof \Laravel\Sanctum\TransientToken) {
            $user->currentAccessToken()->delete();
        }
    }

    return response()->json(['message' => 'Logged out']);
}

}
