<?php

namespace App\Http\Requests\Auth;

use App\Helpers\ResponseFormatter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => 'required|string|exists:password_reset_tokens,token',
            'new_password' => 'required|string|min:8|confirmed',
            'fingerprint' => 'required|string', // FIX: wajib ada
        ];
    }

    public function messages()
    {
        return [
            'token.required' => 'Token reset password harus disertakan',
            'token.exists' => 'Token tidak valid',
            'new_password.required' => 'Password baru harus diisi',
            'new_password.string' => 'Password baru harus berupa string',
            'new_password.min' => 'Password baru harus terdiri dari minimal 8 karakter',
            'new_password.confirmed' => 'Konfirmasi password baru tidak cocok',
            'fingerprint.required' => 'Verifikasi perangkat gagal',
        ];
    }

    public function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(ResponseFormatter::error(
            $validator->errors(),
            'Validation failed',
            422,
            false
        ));
    }
}