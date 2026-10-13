<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransactionRequest extends FormRequest
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
        'transaction_date' => 'required|date',
        'subtotal' => 'required|numeric|min:0',
        'transaction_discount' => 'nullable|numeric|min:0', 
        'transaction_tax' => 'nullable|numeric|min:0',      
        'total_transaction' => 'required|numeric|min:0',
        'name_other_cost' => 'nullable|string|max:255',
        'other_cost' => 'nullable|numeric|min:0',
        'total_payment' => 'required|numeric|min:0',
        'change_amount' => 'nullable|numeric|min:0',
        'transaction_description' => 'nullable|string',
        'total_profit' => 'required|numeric|min:0',
        'customer_id' => 'nullable|exists:customers,id',
        'company_payment_method_id' => 'required|exists:company_payment_methods,id',
        'details' => 'required|array|min:1',
        'details.*.product_id' => 'required|exists:products,id',
        'details.*.quantity' => 'required|integer|min:1',
        'details.*.selling_price' => 'required|numeric|min:0',
        'details.*.cost_price' => 'required|numeric|min:0',
        'details.*.subtotal' => 'required|numeric|min:0',
    ];
    }
}
