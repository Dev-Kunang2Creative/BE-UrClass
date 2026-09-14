<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\NomorPonsel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * Berapa kali sebuah email boleh gagal memverifikasi diri sebelum jalur
     * reset dikunci untuk email itu.
     *
     * Throttle per IP saja tidak cukup: yang dirahasiakan di jalur ini hanya
     * tanggal lahir dan nomor ponsel, dan tanggal lahir punya kemungkinan yang
     * sedikit. Penebak yang berpindah-pindah IP akan lolos dari throttle rute
     * tapi tertahan penghitung per email ini.
     */
    private const BATAS_GAGAL_PER_EMAIL = 5;

    /** Umur token reset. Pendek, karena tokennya langsung dipegang peminta. */
    private const MENIT_TOKEN_RESET = 15;

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
     * Langkah 1 lupa password: buktikan diri lewat data profil, dapatkan token.
     *
     * Tanpa SMTP tidak ada kanal luar untuk mengirim tautan, jadi pembuktiannya
     * memakai dua data yang sudah wajib diisi peserta di profilnya - tanggal
     * lahir dan nomor ponsel. Keduanya lemah kalau berdiri sendiri: orang
     * terdekat bisa saja tahu. Karena itu yang menahan penyalahgunaan bukan
     * kerahasiaan datanya, melainkan batas percobaannya - Turnstile, throttle
     * per IP di rute, dan penghitung gagal per email di bawah ini. Ketiganya
     * satu paket; melepas salah satunya membuat sisanya hampir tidak berarti.
     *
     * Galat untuk data yang tidak cocok sengaja seragam dan tidak menyebut
     * bagian mana yang salah, juga tidak membedakan "email tidak terdaftar"
     * dari "tanggal lahirnya keliru". Membedakannya akan mengubah endpoint ini
     * jadi alat memeriksa email mana yang punya akun.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'birth_date' => ['required', 'date'],
            'phone_number' => ['required', 'string', 'max:20'],
        ]);

        $turnstileToken = $request->input('cf_turnstile_response') ?? $request->input('cf-turnstile-response');
        if (! \App\Services\TurnstileService::verify($turnstileToken, $request->ip())) {
            throw ValidationException::withMessages([
                'cf_turnstile_response' => ['Verifikasi keamanan Turnstile gagal atau telah kedaluwarsa. Silakan coba lagi.'],
            ]);
        }

        $email = strtolower(trim($validated['email']));
        $kunciGagal = 'reset-password-gagal:'.sha1($email);

        if (Cache::get($kunciGagal, 0) >= self::BATAS_GAGAL_PER_EMAIL) {
            throw ValidationException::withMessages([
                'email' => ['Terlalu banyak percobaan untuk akun ini. Coba lagi satu jam lagi, atau hubungi admin lewat WhatsApp.'],
            ]);
        }

        $user = User::real()->where('email', $email)->first();
        $ponsel = NomorPonsel::normalkan($validated['phone_number']);

        // Setiap syarat di bawah harus benar-benar ada isinya. Akun yang
        // profilnya belum lengkap tidak punya pembanding, dan kalau kolom
        // kosong dibandingkan dengan kiriman kosong keduanya akan "cocok" -
        // itu berarti akun paling telantar justru yang paling mudah diambil.
        $cocok = $user
            && ($user->role ?? 'user') !== 'admin'
            && $user->birth_date
            && $ponsel !== ''
            && NomorPonsel::normalkan($user->phone_number) === $ponsel
            && Carbon::parse($user->birth_date)->toDateString()
                === Carbon::parse($validated['birth_date'])->toDateString();

        if (! $cocok) {
            Cache::put($kunciGagal, Cache::get($kunciGagal, 0) + 1, now()->addHour());
            AuditLogger::log('Auth', 'forgot-password-gagal',
                "Verifikasi lupa password gagal untuk {$email} dari IP {$request->ip()}", $user);

            throw ValidationException::withMessages([
                'email' => ['Data yang kamu masukkan tidak cocok dengan akun mana pun. Periksa lagi, atau hubungi admin lewat WhatsApp.'],
            ]);
        }

        // Baru diberitahukan setelah verifikasi lolos, jadi bukan alat menebak
        // akun mana yang memakai Google. Endpoint login sudah menyatakan hal
        // yang sama, dengan kalimat yang sama.
        if (! $user->password) {
            throw ValidationException::withMessages([
                'email' => ['Akun ini terdaftar menggunakan Google. Silakan login menggunakan Google OAuth.'],
            ]);
        }

        $token = Str::random(64);

        // Yang disimpan hash-nya, bukan tokennya. Kalau tabel ini bocor, isinya
        // tidak bisa dipakai untuk mengambil alih akun mana pun.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()],
        );

        Cache::forget($kunciGagal);
        AuditLogger::log('Auth', 'forgot-password',
            "Token reset password diterbitkan untuk {$user->name} ({$email})", $user);

        return response()->json([
            'message' => 'Verifikasi berhasil. Silakan buat password baru.',
            'data' => [
                'email' => $email,
                'token' => $token,
                'expires_in_minutes' => self::MENIT_TOKEN_RESET,
            ],
        ]);
    }

    /**
     * Langkah 2 lupa password: tukar token dengan password baru.
     *
     * Bentuknya sengaja sama persis dengan alur reset bawaan Laravel - email,
     * token, password - supaya saat SMTP tersedia nanti yang perlu diganti
     * hanya cara token sampai ke peserta, bukan endpoint ini.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $email = strtolower(trim($validated['email']));
        $baris = DB::table('password_reset_tokens')->where('email', $email)->first();

        $kedaluwarsa = $baris
            && Carbon::parse($baris->created_at)->addMinutes(self::MENIT_TOKEN_RESET)->isPast();

        if ($kedaluwarsa) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        }

        // Token yang salah tidak menghapus barisnya: kalau iya, siapa pun yang
        // tahu alamat email seseorang bisa membatalkan proses reset yang sedang
        // berjalan hanya dengan menebak sekali.
        if (! $baris || $kedaluwarsa || ! Hash::check($validated['token'], $baris->token)) {
            throw ValidationException::withMessages([
                'token' => ['Token reset tidak berlaku atau sudah kedaluwarsa. Ulangi dari langkah pertama.'],
            ]);
        }

        $user = User::real()->where('email', $email)->first();

        if (! $user) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            throw ValidationException::withMessages([
                'token' => ['Token reset tidak berlaku atau sudah kedaluwarsa. Ulangi dari langkah pertama.'],
            ]);
        }

        // Cast 'hashed' di model yang melakukan hashing-nya.
        $user->password = $validated['password'];
        $user->save();

        // Sekali pakai: barisnya hilang begitu terpakai.
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Reset password harus mengusir siapa pun yang sedang memegang akun ini
        // di perangkat lain - itu justru alasan paling sering orang meresetnya.
        $user->tokens()->delete();

        AuditLogger::log('Auth', 'reset-password',
            "Password direset mandiri: {$user->name} ({$email})", $user);

        return response()->json([
            'message' => 'Password berhasil diubah. Silakan login dengan password barumu.',
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
