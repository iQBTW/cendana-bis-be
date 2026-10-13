<?php

namespace App\Http\Requests\Auth;

use App\Helpers\ResponseFormatter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'email|required',
            'password' => 'string|required',
            'device_token' => 'nullable|string',
            'device_id' => 'string|nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email diperlukan, mohon diisi',
            'email.email' => 'Format email tidak valid',
            'password.required' => 'Password diperlukan, mohon diisi',
            'device_token.string' => 'Device token harus berupa string',
            'device_id.string' => 'Device ID harus berupa string',
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
