<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetToken;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Helpers\ResponseFormatter;
use Illuminate\Support\Facades\Validator;
use App\Mail\OTPMail;
use App\Mail\ForgetPasswordMail;
use Illuminate\Support\Facades\Mail;
use Exception;

class UserController extends Controller
{

    // Register Owner

    public function registerOwner(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    'regex:/^[A-Za-zÀ-ÿ\s\'\.\-]+$/u', // hanya huruf, spasi, titik, apostrof, strip
                ],
                'email' => 'required|email:rfc,dns|unique:users,email',
                'password' => 'required|string|min:8',
                'confirmPassword' => 'required|same:password',
                'noTelephone' => [
                    'required',
                    'numeric',
                    'digits_between:10,12',
                    'unique:users,phone_number',
                ],
                'referral_source' => 'nullable|string|max:255',
            ], [
                'name.regex' => 'Nama hanya boleh berisi huruf dan spasi.',
                'noTelephone.digits_between' => 'No. telepon harus 10-13 digit.',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors();

                if ($errors->has('email')) {
                    return ResponseFormatter::error(
                        ['email' => $errors->first('email')],
                        'Email sudah terdaftar',
                        422,
                        false
                    );
                }

                if ($errors->has('noTelephone')) {
                    return ResponseFormatter::error(
                        ['phone' => $errors->first('noTelephone')],
                        $errors->first('noTelephone'),
                        422,
                        false
                    );
                }

                if ($errors->has('name')) {
                    return ResponseFormatter::error(
                        ['name' => $errors->first('name')],
                        'Nama tidak valid',
                        422,
                        false
                    );
                }

                return ResponseFormatter::error(
                    $errors->all(),
                    'Validation failed',
                    422,
                    false
                );
            }


            $owner = User::create([
                'full_name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone_number' => $request->noTelephone,
                'referral_source' => $request->referral_source,
            ]);


            try {
                $owner->assignRole('owner');
            } catch (\Exception $e) {
                // Kalau role gagal, hapus user yang baru dibuat agar tidak orphan
                $owner->delete();
                Log::error('Register owner - assignRole failed: ' . $e->getMessage());
                return ResponseFormatter::error(
                    null,
                    'Konfigurasi role belum siap. Hubungi administrator.',
                    500,
                    false
                );
            }

            $otp = rand(100000, 999999);
            $owner->otp_code = $otp;
            $owner->otp_expires_at = now()->addMinutes(1);
            $owner->save();

            try {
                Mail::to($owner->email)->send(new OTPMail($otp));
            } catch (\Exception $e) {
                // User sudah terbuat & role sudah assign, tapi mail gagal
                // Jangan batalkan registrasi, cukup log dan beritahu user
                Log::error('Register owner - Send OTP email failed: ' . $e->getMessage(), [
                    'user_id' => Crypt::encryptString($owner->id),
                    'email' => $owner->email,
                ]);

                // Tetap return success tapi dengan flag email_sent = false
                return ResponseFormatter::success(
                    [
                        'user_id' => Crypt::encryptString($owner->id),
                        'requires_verification' => true,
                        'email_sent' => false, // frontend bisa tampilkan pesan berbeda
                    ],
                    'Registrasi berhasil, namun email OTP gagal dikirim. Gunakan fitur kirim ulang OTP.',
                    null,
                    201
                );
            }

            return ResponseFormatter::success(
                [
                    'user_id' => Crypt::encryptString($owner->id),
                    'requires_verification' => true,
                    'email_sent' => true,
                ],
                'Owner registered successfully. OTP has been sent to email.',
                null,
                201
            );
        } catch (\Exception $e) {

            Log::error('Owner registration failed: ' . $e->getMessage(), [
                'request_data' => [
                    'name' => $request->name,
                    'email' => $request->email,
                    'noTelephone' => $request->noTelephone,
                ],
                'trace' => $e->getTraceAsString()
            ]);

            return ResponseFormatter::error(
                null,
                'Terjadi kesalahan pada server: ' . $e->getMessage(),
            );
        }
    }



    // Register Cashier
    public function registerCashier(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'full_name' => 'required|string|max:255',
                'email' => 'required|string|email:rfc,dns|max:255|unique:users,email',
                'password' => 'required|string|min:8',
                'phone_number' => 'required|string|max:20',
                'user_address' => 'required|string|max:255',
                'source' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return ResponseFormatter::error(
                    $validator->errors(),
                    'Validation failed',
                    422,
                    false
                );
            }

            $cashier = User::create([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone_number' => $request->phone_number,
                'user_address' => $request->user_address,
                'source' => $request->source,
            ]);

            $cashier->assignRole('cashier');

            return ResponseFormatter::success(
                $cashier->makeHidden(['password']),
                'Cashier registered successfully',
                true,
                201
            );
        } catch (Exception $e) {
            Log::error('Cashier registration failed: ' . $e->getMessage(), [
                'owner_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString()
            ]);

            return ResponseFormatter::error(
                null,
                'unexpeted error',
                500,
                false
            );
        }
    }

    public function getUsers(Request $request)
    {
        try {
            $users = User::with('role')
                ->when($request->search, function ($query) use ($request) {
                    $query->where('full_name', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%')
                        ->orWhere('phone_number', 'like', '%' . $request->search . '%');
                })
                ->orderBy('created_at', 'desc')
                ->paginate($request->limit ?? 10);

            // Format data untuk response
            $users->getCollection()->transform(function ($user) {
                return [
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'role_name' => $user->role ? $user->role->name : null,
                    'user_address' => $user->user_address,
                ];
            });

            return ResponseFormatter::success(
                $users,
                'Users retrieved successfully',
                200,
                true
            );
        } catch (Exception $e) {
            Log::error('Get users failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return ResponseFormatter::error(
                null,
                'Unexpected error',
                500,
                false
            );
        }
    }
    public function getEmailByUserId(Request $request)
    {
        $rawUserId = $request->input('user_id');

        try {
            $decryptedId = Crypt::decryptString($rawUserId);
        } catch (\Exception $e) {
            return ResponseFormatter::error(null, 'ID tidak valid', 404, false);
        }

        $user = User::find($decryptedId);

        if (!$user) {
            return ResponseFormatter::error(null, 'User not found', 404, false);
        }

        return ResponseFormatter::success([
            'email' => $user->email
        ]);
    }

    public function verifyOtp(Request $request)
    {
        try {
            $rawUserId = $request->input('user_id');
            try {
                $decryptedUserId = Crypt::decryptString($rawUserId);
            } catch (\Exception $e) {
                return ResponseFormatter::error(null, 'ID pengguna tidak valid', 422);
            }

            $request->merge(['user_id' => $decryptedUserId]);

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'otp_code' => 'required|digits:6',
            ]);

            if ($validator->fails()) {
                return ResponseFormatter::error(
                    $validator->errors(),
                    'Validation failed',
                    422
                );
            }

            $user = User::find($request->user_id);

            if ($user->email_verified_at) {
                return ResponseFormatter::error(
                    null,
                    'Email sudah diverifikasi',
                    400
                );
            }

            if ($user->otp_code != $request->otp_code) {
                return ResponseFormatter::error(
                    null,
                    'Kode OTP tidak sesuai',
                    400
                );
            }

            if (now()->greaterThan($user->otp_expires_at)) {
                return ResponseFormatter::error(
                    null,
                    'OTP kedaluwarsa',
                    400
                );
            }

            $user->email_verified_at = now();
            $user->otp_code = null;
            $user->otp_expires_at = null;
            $user->save();

            return ResponseFormatter::success(
                ['verified' => true,],
                'Email berhasil diverifikasi',
                200
            );
        } catch (\Exception $e) {

            Log::error('OTP verification failed: ' . $e->getMessage());

            return ResponseFormatter::error(
                null,
                'Unexpected error',
                500
            );
        }
    }

    public function resendOtp(Request $request)
    {
        try {
            $rawUserId = $request->input('user_id');
            if ($rawUserId) {
                try {
                    $decryptedUserId = Crypt::decryptString($rawUserId);
                    $request->merge(['user_id' => $decryptedUserId]);
                } catch (\Exception $e) {
                    $request->merge(['user_id' => null]);
                }
            }

            $validator = Validator::make($request->all(), [
                'user_id' => 'nullable|exists:users,id',
                'email' => 'nullable|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return ResponseFormatter::error(
                    $validator->errors(),
                    'Pengguna tidak ada',
                    422,
                    false
                );
            }

            $user = null;

            if ($request->user_id) {
                $user = User::find($request->user_id);
            } elseif ($request->email) {
                $user = User::where('email', $request->email)->first();
            }

            if (!$user) {
                return ResponseFormatter::error(
                    null,
                    'Pengguna tidak ditemukan',
                    404,
                    false
                );
            }

            if (!is_null($user->email_verified_at)) {
                return ResponseFormatter::error(
                    null,
                    'Email sudah diverifikasi',
                    400,
                    false
                );
            }

            $newOtp = rand(100000, 999999);

            $user->otp_code = $newOtp;
            $user->otp_expires_at = now()->addMinutes(10);
            $user->save();

            Mail::to($user->email)->send(new OTPMail($newOtp));

            return ResponseFormatter::success(
                [
                    'user_id' => Crypt::encryptString($user->id),
                    'otp_sent' => true
                ],
                'OTP baru berhasil dikirim.',
                null,
                200
            );
        } catch (\Exception $e) {

            Log::error('Resend OTP failed: ' . $e->getMessage());

            return ResponseFormatter::error(
                null,
                'Unexpected error',
                500,
                false
            );
        }
    }

    public function sendResetPasswordEmail(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'fingerprint' => 'required|string',
            ]);

            if ($validator->fails()) {
                return ResponseFormatter::error(
                    $validator->errors()->first(),
                    'Validasi gagal',
                    422,
                    false
                );
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return ResponseFormatter::error(
                    null,
                    'Email tidak ditemukan di sistem kami.',
                    404,
                    false
                );
            }

            PasswordResetToken::where('user_id', $user->id)->delete();

            $token = Str::random(64);

            // Ambil cookie_session_id dari cookie yang sudah ada
            $cookieSessionId = $request->cookie('reset_password_session') ?? Str::random(40);

            PasswordResetToken::create([
                'user_id' => $user->id,
                'token' => $token,
                'fingerprint' => $request->fingerprint,
                'expires_at' => now()->addMinutes(15),
                'is_used' => false,
            ]);

            Mail::to($user->email)->send(new ForgetPasswordMail($user, $token));

            // Set cookie httpOnly — tidak bisa dibaca/dicuri lewat JavaScript,
            // otomatis terkirim tiap request selama masih di browser yang sama.
            $cookie = cookie(
                'reset_password_session',
                $cookieSessionId,
                30,
                '/',
                null,
                false,
                true,
                false,
                'Lax'
            );

            return ResponseFormatter::success(
                [
                    'email' => $user->email,
                    'sent' => true
                ],
                'Link untuk mengubah password telah dikirim ke email Anda.',
                null,
                true
            )->withCookie($cookie);
        } catch (Exception $e) {
            Log::error('Send reset password email failed: ' . $e->getMessage(), [
                'email' => $request->email,
                'trace' => $e->getTraceAsString()
            ]);

            return ResponseFormatter::error(
                null,
                'Gagal mengirim email reset password. Silakan coba lagi nanti.',
                500,
                false
            );
        }
    }
}
