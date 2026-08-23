<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileService
{
    /**
     * Verify Cloudflare Turnstile token.
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        $secretKey = config('services.turnstile.secret_key');

        // Allow bypass in local testing if test token or environment allows
        if ($token === 'test-bypass-token' && app()->environment('local', 'testing')) {
            return true;
        }

        // Test keys from Cloudflare: 1x0000000000000000000000000000000AA always passes
        if (empty($token)) {
            // In local development, if no token was passed, allow if configured
            if (app()->environment('local', 'testing') && env('CLOUDFLARE_TURNSTILE_OPTIONAL_LOCAL', true)) {
                return true;
            }
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secretKey ?: '1x0000000000000000000000000000000AA',
                'response' => $token,
                'remoteip' => $ip,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return (bool) ($data['success'] ?? false);
            }

            Log::warning('Cloudflare Turnstile verification response error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('Cloudflare Turnstile exception: ' . $e->getMessage());
            // Fail closed on error in production, allow in local testing if needed
            return app()->environment('local', 'testing');
        }
    }
}
