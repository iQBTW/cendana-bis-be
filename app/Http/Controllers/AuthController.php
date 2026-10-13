<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseFormatter;
use App\Helpers\SubscriptionAccessHelper;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Company;
use App\Models\PasswordResetToken;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const ACCESS_MINUTES      = 4320;
    private const REFRESH_MINUTES     = 10080;
    private const REFRESH_COOKIE_PATH = '/api';
    private const WEB_DEVICE          = 'web';
    private const ACCESS_SUFFIX       = '_access_token';
    private const REFRESH_SUFFIX      = '_refresh_token';

    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        try {
            $user = User::where('email', $validated['email'])->first();

            if (!$user) {
                return ResponseFormatter::error(
                    null,
                    $validated['email'] . " tidak dikenali. Silakan cek kembali.",
                    422,
                    false
                );
            }

            if (!Hash::check($validated['password'], $user->password)) {
                return ResponseFormatter::error(
                    null,
                    'Password salah! Silakan cek kembali.',
                    422,
                    false
                );
            }

            $role = $user->getRoleNames()->first();

            if ($role === 'owner' && !$user->email_verified_at) {
                return ResponseFormatter::success([
                    'requires_verification' => true,
                    'user_id' => $user->id,
                    'email' => $user->email,
                ], 'Email belum diverifikasi. Silakan verifikasi terlebih dahulu.', null, 200);
            }

            $targetCompanyId = null;
            $companyName = null;
            $allCompanies = collect();

            $isPremium = false;
            $planName = 'Basic / Free';
            $subscriptionEndsAt = null;

            if ($role === 'owner') {
                $companies = $user->companies()
                    ->select('id', 'store_name', 'created_at')
                    ->orderBy('created_at', 'asc')
                    ->get();

                $accessInfo = SubscriptionAccessHelper::getAccessInfo($user->id);

                if ($accessInfo['has_active_subscription']) {
                    $isPremium = true;
                    $planName = $accessInfo['subscription']->plan->name ?? 'Premium';
                    $subscriptionEndsAt = $accessInfo['subscription']->ends_at;
                }

                $outletLimit = SubscriptionAccessHelper::resolveOutletLimit($accessInfo);

                $allCompanies = $outletLimit === null
                    ? $companies
                    : $companies->take($outletLimit)->values();

                if ($allCompanies->isNotEmpty()) {
                    $firstCompany = $allCompanies->first();
                    $targetCompanyId = $firstCompany->id;
                    $companyName = $firstCompany->store_name;
                }
            } elseif ($role === 'superadmin') {
                $targetCompanyId = null;
                $companyName = null;
                $allCompanies = collect();
                $isPremium = false;
                $planName = 'Basic / Free';
                $subscriptionEndsAt = null;
            } else {
                // Aturan akses kasir
                if ($user->company) {
                    $ownerId = $user->company->owner_id;

                    $accessInfo = $ownerId
                        ? SubscriptionAccessHelper::getAccessInfo($ownerId)
                        : ['has_active_subscription' => false, 'max_outlets' => 1, 'subscription' => null];

                    $hasActiveSubscription = $accessInfo['has_active_subscription'] ?? false;

                    $outletLimit = SubscriptionAccessHelper::resolveOutletLimit($accessInfo);

                    if ($outletLimit !== null) {
                        $allowedCompanyIds = Company::where('owner_id', $ownerId)
                            ->orderBy('created_at', 'asc')
                            ->limit($outletLimit)
                            ->pluck('id')
                            ->toArray();

                        if (!in_array((int) $user->company_id, $allowedCompanyIds, true)) {
                            $message = $hasActiveSubscription
                                ? "Paket langganan pemilik toko hanya mendukung {$outletLimit} cabang. Cabang ini di luar kuota paket. Silakan hubungi pemilik toko."
                                : 'Toko ini tidak memiliki langganan aktif. Silakan hubungi pemilik toko.';

                            return ResponseFormatter::error(null, $message, 403, false);
                        }
                    }

                    // Batas jumlah kasir yang boleh login di cabang ini.
                    if ($hasActiveSubscription && !empty($accessInfo['subscription']->plan_id)) {
                        $maxUsers = DB::table('plan_feature_items')
                            ->join('plan_features', 'plan_features.id', '=', 'plan_feature_items.feature_id')
                            ->where('plan_feature_items.plan_id', $accessInfo['subscription']->plan_id)
                            ->where('plan_features.feature_key', 'max_users')
                            ->value('plan_feature_items.value');
                    } else {
                        $maxUsers = 5;
                    }

                    // -1 = unlimited.
                    if ($maxUsers !== null && (int) $maxUsers !== -1) {
                        $orderedCashierIds = User::where('company_id', $user->company_id)
                            ->role('cashier')
                            ->orderBy('created_at', 'asc')
                            ->pluck('id');

                        $position = $orderedCashierIds->search($user->id); // 0-based, false kalau tidak ketemu
                        $rank = $position === false ? null : $position + 1;

                        if ($rank === null || $rank > (int) $maxUsers) {
                            return ResponseFormatter::error(
                                null,
                                'Batas jumlah kasir yang dapat login untuk paket langganan ini sudah tercapai. Hubungi pemilik toko untuk upgrade paket.',
                                403,
                                false
                            );
                        }
                    }

                    $companyName = $user->company->store_name;
                }

                $allCompanies = $user->company
                    ? collect([$user->company->only(['id', 'store_name'])])
                    : collect();

                $targetCompanyId = $user->company_id;
            }

            $deviceToken = $validated['device_token'] ?? 'web_access_token';
            $deviceName  = $validated['device_id'] ?? ($deviceToken === 'web_access_token' ? self::WEB_DEVICE : $deviceToken);
            $isWeb       = $deviceName === self::WEB_DEVICE;

            // Satu perangkat satu sesi: cabut access dan refresh token lama
            $this->revokeDeviceTokens($user, $deviceName);

            $token = $user->createToken(
                $this->accessTokenName($deviceName),
                ['*'],
                now()->addMinutes(self::ACCESS_MINUTES)
            )->plainTextToken;

            $refreshToken = $user->createToken(
                $this->refreshTokenName($deviceName),
                ['issue-access-token'],
                now()->addMinutes(self::REFRESH_MINUTES)
            )->plainTextToken;

            $payload = [
                'login_type' => $deviceToken,
                'token_type' => 'Bearer',
                'user' => $user->makeHidden(['password']),
                'role' => $user->getRoleNames()->first(),
                'active_company_id' => $targetCompanyId,
                'company_name' => $companyName,
                'is_premium' => $isPremium,
                'plan_name' => $planName,
                'subscription_ends_at' => $subscriptionEndsAt,
                'companies' => $allCompanies,
            ];

            if ($isWeb) {
                // Web: token hanya lewat cookie httpOnly
                return ResponseFormatter::success($payload, 'Login ' . $deviceToken . ' berhasil', null, true)
                    ->withCookie($this->makeCookie('access_token', $token, self::ACCESS_MINUTES))
                    ->withCookie($this->makeCookie('refresh_token', $refreshToken, self::REFRESH_MINUTES, self::REFRESH_COOKIE_PATH));
            }

            // Mobile: toke
            $payload['access_token']  = $token;
            $payload['refresh_token'] = $refreshToken;

            return ResponseFormatter::success($payload, 'Login ' . $deviceToken . ' berhasil', null, true);
        } catch (Exception $e) {
            Log::error('Login failed: ' . $e->getMessage(), [
                'email' => $request->email,
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseFormatter::error(null, $e->getMessage(), 500, false);
        }
    }

    // ------------------------------------------------------------------
    // Refresh access token
    // ------------------------------------------------------------------

    public function refreshAccessToken(Request $request)
    {
        try {
            // Web lewat cookie, mobile lewat body
            $plain = $request->cookie('refresh_token');

            if (!$plain) {
                return $this->forgetAuthCookies(
                    ResponseFormatter::error(null, 'Refresh token tidak ditemukan.', 401, false)
                );
            }

            $refresh = PersonalAccessToken::findToken($plain);

            if (
                !$refresh
                || !str_ends_with($refresh->name, self::REFRESH_SUFFIX)
                || !$refresh->can('issue-access-token')
                || ($refresh->expires_at && $refresh->expires_at->isPast())
                || !$refresh->tokenable
            ) {
                return $this->forgetAuthCookies(
                    ResponseFormatter::error(null, 'Sesi berakhir. Silakan login kembali.', 401, false)
                );
            }

            $user       = $refresh->tokenable;
            $deviceName = $this->deviceFromTokenName($refresh->name);

            $new = $user->createToken(
                $this->accessTokenName($deviceName),
                ['*'],
                now()->addMinutes(self::ACCESS_MINUTES)
            );

            // Cabut semua access token lama untuk perangkat ini
            $user->tokens()
                ->where('name', $this->accessTokenName($deviceName))
                ->where('id', '!=', $new->accessToken->id)
                ->delete();

            if ($deviceName === self::WEB_DEVICE) {
                return ResponseFormatter::success(['token_type' => 'Bearer'], 'Token diperbarui', null, true)
                    ->withCookie($this->makeCookie('access_token', $new->plainTextToken, self::ACCESS_MINUTES));
            }

            return ResponseFormatter::success([
                'token_type'   => 'Bearer',
                'access_token' => $new->plainTextToken,
            ], 'Token diperbarui', null, true);
        } catch (Exception $e) {
            Log::error('Refresh token failed: ' . $e->getMessage());
            return ResponseFormatter::error(null, 'Terjadi kesalahan pada server.', 500, false);
        }
    }

    // ------------------------------------------------------------------
    // Reset password
    // ------------------------------------------------------------------

    public function validateResetToken(Request $request, string $token)
    {
        try {
            $resetToken = PasswordResetToken::where('token', $token)->first();

            if (!$resetToken || !$resetToken->isValid()) {
                return ResponseFormatter::error(
                    null,
                    'Link reset password tidak valid atau sudah kedaluwarsa.',
                    400,
                    false
                );
            }

            $incomingFingerprint = $request->query('fingerprint');
            $incomingCookie = $request->cookie('reset_password_session');

            // Cocok kalau SALAH SATU match: fingerprint ATAU cookie.
            $fingerprintMatch = $incomingFingerprint && $resetToken->fingerprint === $incomingFingerprint;
            $cookieMatch = $incomingCookie && $resetToken->cookie_session_id === $incomingCookie;

            if (!$fingerprintMatch && !$cookieMatch) {
                return ResponseFormatter::error(
                    null,
                    'Link ini hanya bisa dibuka dari perangkat yang sama saat permintaan reset dibuat.',
                    403,
                    false
                );
            }

            return ResponseFormatter::success(['valid' => true], 'Token valid', null, true);
        } catch (Exception $e) {
            Log::error('Validate reset token failed: ' . $e->getMessage());
            return ResponseFormatter::error(null, 'Terjadi kesalahan pada server.', 500, false);
        }
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        try {
            $validated = $request->validated();

            $resetToken = PasswordResetToken::where('token', $validated['token'])->first();

            if (!$resetToken || !$resetToken->isValid()) {
                return ResponseFormatter::error(null, 'Token tidak valid atau sudah kedaluwarsa.', 400, false);
            }

            $incomingFingerprint = $validated['fingerprint'] ?? null;
            $incomingCookie = $request->cookie('reset_password_session');

            $fingerprintMatch = $incomingFingerprint && $resetToken->fingerprint === $incomingFingerprint;
            $cookieMatch = $incomingCookie && $resetToken->cookie_session_id === $incomingCookie;

            if (!$fingerprintMatch && !$cookieMatch) {
                return ResponseFormatter::error(
                    null,
                    'Perangkat tidak sesuai dengan permintaan reset password.',
                    403,
                    false
                );
            }

            $user = User::find($resetToken->user_id);

            if (!$user) {
                return ResponseFormatter::error(null, 'Pengguna tidak ditemukan.', 404, false);
            }

            DB::transaction(function () use ($user, $resetToken, $validated) {
                $user->password = Hash::make($validated['new_password']);
                $user->save();

                // Cabut semua token Sanctum (access + refresh)
                $user->tokens()->delete();

                // Cabut semua token OAuth (Passport). User tidak lagi memakai trait Passport,
                // jadi query langsung ke model token Passport.
                Passport::tokenModel()::where('user_id', $user->id)->update(['revoked' => true]);

                $resetToken->update(['is_used' => true]);
            });

            return ResponseFormatter::success(null, 'Password berhasil diubah.', null, true);
        } catch (Exception $e) {
            Log::error('Reset password failed: ' . $e->getMessage());
            return ResponseFormatter::error(null, 'Terjadi kesalahan pada server.', 500, false);
        }
    }

    // ------------------------------------------------------------------
    // Logout
    // ------------------------------------------------------------------

    public function logout(Request $request)
    {
        try {
            // Route logout di luar middleware auth, jadi guard dipanggil eksplisit
            $user  = $request->user('sanctum');
            $token = $user?->currentAccessToken();

            // Refresh token dicabut lewat cookie, walau access token sudah kedaluwarsa
            $plain   = $request->cookie('refresh_token');
            $refresh = $plain ? PersonalAccessToken::findToken($plain) : null;
            $refresh?->delete();

            $message = 'Logout web successful';

            // Hanya token login Sanctum yang dicabut lewat logout.
            // API key (Passport) dicabut lewat DELETE /api-keys/{id}.
            if ($token instanceof PersonalAccessToken) {
                $deviceName = $this->deviceFromTokenName($token->name);

                if ($deviceName !== self::WEB_DEVICE) {
                    $message = 'Logout mobile successful';
                }

                $this->revokeDeviceTokens($user, $deviceName);
                $token->delete();
            }

            return $this->forgetAuthCookies(
                ResponseFormatter::success(null, $message, null, true)
            );
        } catch (Exception $e) {
            Log::error('Logout failed: ' . $e->getMessage(), [
                'user_id' => $request->user('sanctum')?->id,
                'trace' => $e->getTraceAsString()
            ]);

            return ResponseFormatter::error(null, 'Terjadi kesalahan pada server.', 500, false);
        }

    }

    private function makeCookie(string $name, string $value, int $minutes, string $path = '/')
    {
        return cookie(
            $name,
            $value,
            $minutes,
            $path,
            null,
            config('variable.cookie_secure'),
            true,
            false,
            config('variable.cookie_samesite', config('variable.cookie_samsite', 'Lax'))
        );
    }

    private function accessTokenName(string $deviceName): string
    {
        return $deviceName . self::ACCESS_SUFFIX;
    }

    private function refreshTokenName(string $deviceName): string
    {
        return $deviceName . self::REFRESH_SUFFIX;
    }

    // web_access_token / web_refresh_token  ->  web
    private function deviceFromTokenName(string $tokenName): string
    {
        return Str::beforeLast(
            Str::beforeLast($tokenName, self::REFRESH_SUFFIX),
            self::ACCESS_SUFFIX
        );
    }

    private function revokeDeviceTokens(User $user, string $deviceName): void
    {
        $user->tokens()
            ->whereIn('name', [
                $this->accessTokenName($deviceName),
                $this->refreshTokenName($deviceName),
                $deviceName, // nama lama (sebelum penamaan baru), ikut dibersihkan
            ])
            ->delete();
    }

    private function forgetAuthCookies($response)
    {
        return $response
            ->withCookie(cookie()->forget('access_token'))
            ->withCookie(cookie()->forget('refresh_token', self::REFRESH_COOKIE_PATH));
    }
}
