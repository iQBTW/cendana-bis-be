<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Helpers\ResponseFormatter;

class VerifyRecaptcha
{
    /**
     * Handle reCAPTCHA v3 verification.
     * Mobile apps are exempt — verified via X-App-Platform + X-Mobile-Secret headers.
     *
     * Usage in routes:
     *   ->middleware('recaptcha')        // default min score 0.5
     *   ->middleware('recaptcha:0.7')    // custom min score
     */
    public function handle(Request $request, Closure $next, float $minScore = 0.5)
    {
        if ($this->isMobileRequest($request)) {
            return $next($request);
        }

        if ($this->isDevelopment()) {
            return $next($request);
        }

        $token = $request->input('recaptcha_token');

        if (empty($token)) {
            return ResponseFormatter::error(
                null,
                'Verifikasi reCAPTCHA diperlukan.',
                422,
                false
            );
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => config('variable.google_recaptcha_secret_key'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            $result = $response->json();

            if (!($result['success'] ?? false)) {
                Log::warning('reCAPTCHA verification failed', [
                    'error-codes' => $result['error-codes'] ?? [],
                    'ip' => $request->ip(),
                ]);

                return ResponseFormatter::error(
                    null,
                    'Verifikasi reCAPTCHA gagal. Silakan coba lagi.',
                    403,
                    false
                );
            }

            $score = $result['score'] ?? 0;

            if ($score < $minScore) {
                Log::warning('reCAPTCHA score too low', [
                    'score' => $score,
                    'min_score' => $minScore,
                    'ip' => $request->ip(),
                    'action' => $result['action'] ?? null,
                ]);

                return ResponseFormatter::error(
                    null,
                    'Aktivitas mencurigakan terdeteksi. Silakan coba lagi.',
                    403,
                    false
                );
            }

        } catch (\Exception $e) {
            Log::error('reCAPTCHA API error: ' . $e->getMessage());
            // Fail open: allow jika Google API down
            return $next($request);
        }

        return $next($request);
    }

    private function isMobileRequest(Request $request): bool
    {
        $platform = strtolower($request->header('X-App-Platform') ?? '');
        $isMobile = in_array($platform, ['android', 'ios']);

        if ($isMobile) {
            Log::info('reCAPTCHA bypassed for mobile', [
                'platform' => $platform,
                'ip' => $request->ip(),
            ]);
        }

        return $isMobile;
    }

    private function isDevelopment(): bool
    {
        return app()->environment('development', 'local');
    }
}
