<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Local-development-only authentication controller.
 * Provides a password-less login flow via user picker.
 * Guarded by APP_ENV=local — this controller must never be accessible in production.
 */
class DevLoginController extends Controller
{
    /**
     * List all available dev users for the picker screen.
     */
    public function users(): JsonResponse
    {
        $users = User::select('id', 'name', 'email')->orderBy('id')->get();

        return response()->json(['users' => $users]);
    }

    /**
     * Log in as a dev user by email (no password required).
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->input('email'))->firstOrFail();
        $user->tokens()->delete();

        $token = $user->createToken('dev-session')->plainTextToken;

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
        ]);
    }

    /**
     * Log out the current user by revoking all their tokens.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out']);
    }
}