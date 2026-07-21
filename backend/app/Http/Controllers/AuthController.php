<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate a user and return a Sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        // Revoke all older tokens for security if desired, but for now we just issue a new one
        $token = $user->createToken('pos_auth_token')->plainTextToken;

        $permissions = \App\Models\RolePermission::where('role', $user->role)->get();

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Log out the user (Invalidate the token).
     */
    public function logout(Request $request): JsonResponse
    {
        // Delete the current access token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada exitosamente.'
        ]);
    }

    /**
     * Get the authenticated User.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $permissions = \App\Models\RolePermission::where('role', $user->role)->get();

        return response()->json([
            'user' => $user,
            'permissions' => $permissions,
        ]);
    }
}
