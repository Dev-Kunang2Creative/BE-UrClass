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
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/u'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'name.regex' => 'Nama tidak boleh mengandung tag HTML atau karakter script.',
        ]);

        $turnstileToken = $request->input('cf_turnstile_response') ?? $request->input('cf-turnstile-response');
        if (! \App\Services\TurnstileService::verify($turnstileToken, $request->ip())) {
            throw ValidationException::withMessages([
                'cf_turnstile_response' => ['Verifikasi keamanan Turnstile gagal atau telah kedaluwarsa. Silakan coba lagi.'],
            ]);
        }

        $user = User::create([
            'name' => strip_tags(trim($validated['name'])),
            'email' => strtolower(trim($validated['email'])),
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

        $user = User::real()->where('email', $validated['email'])->first();
        
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

    /**
     * Target for the route named "login", which this API-only app never had.
     *
     * Laravel falls back to route('login') when it decides an unauthenticated
     * request should be redirected rather than answered with JSON. Two
     * attempts to stop it deciding that - shouldRenderJsonWhen covering
     * api/*, then an explicit render() callback for AuthenticationException -
     * both failed to take effect on this deployment, while a ValidationException
     * on the same host does render as JSON without an Accept header. Rather
     * than keep guessing at why, the route it asks for now exists and answers
     * 401, so no path can end in a 500 about a missing route.
     */
    public function loginNotice(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
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
            
            $user = User::real()->where('email', $googleUser->getEmail())->first();

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

            $frontendUrl = config('app.frontend_url');
            
            return redirect()->away($frontendUrl . '/auth/callback?token=' . $token);

        } catch (\Exception $e) {
            $frontendUrl = config('app.frontend_url');
            return redirect()->away($frontendUrl . '/login?error=google_auth_failed');
        }
    }
}
