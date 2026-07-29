<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role' => 'user',
        ]);

        $tokenRaw = $user->createToken('auth-token')->plainTextToken;
        $token = explode('|', $tokenRaw, 2)[1];

        AuditLogger::log('Auth', 'register', "Pengguna baru mendaftar: {$user->name} ({$user->email})", $user);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();
        
        if ($user && !$user->password) {
            throw ValidationException::withMessages([
                'email' => ['Akun ini terdaftar menggunakan Google. Silakan login menggunakan Google OAuth.'],
            ]);
        }
        
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        // --- SINGLE DEVICE LOGIN ---
        $user->tokens()->delete();

        $tokenRaw = $user->createToken('auth-token')->plainTextToken;
        $token = explode('|', $tokenRaw, 2)[1];

        AuditLogger::log('Auth', 'login', "Login berhasil: {$user->name} ({$user->email})", $user);

        return response()->json([
            'message' => 'Login berhasil',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        AuditLogger::log('Auth', 'logout', "Logout: {$user->name} ({$user->email})", $user);
        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }

    // --- GOOGLE OAUTH METHODS ---

    public function redirectToGoogle(): JsonResponse
    {
        return response()->json([
            'url' => Socialite::driver('google')->stateless()->redirect()->getTargetUrl(),
        ]);
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => null, 
                    'google_id' => $googleUser->getId(),
                    'role' => 'user',
                ]);
            } else {
                if (!$user->google_id) {
                    $user->update([
                        'google_id' => $googleUser->getId()
                    ]);
                }
            }

            // --- SINGLE DEVICE LOGIN ---
            $user->tokens()->delete();

            $tokenRaw = $user->createToken('auth-token')->plainTextToken;
            $token = explode('|', $tokenRaw, 2)[1];

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            
            return redirect()->away($frontendUrl . '/auth/callback?token=' . $token);

        } catch (\Exception $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect()->away($frontendUrl . '/login?error=google_auth_failed');
        }
    }
}