<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
        'payment_methode_name',
        'type_payment_method',

    ];

    public function bankAccount()
    {
        return $this->hasMany(BankAccount::class);
    }

    public function transaction()
    {
        return $this->hasMany(Transaction::class);
    }

    public function companies()
    {
        return $this->belongsToMany(
            Company::class,
            'company_payment_methods',
            'payment_method_id',
            'company_id'
        );
    }

    public function companyPaymentMethods()
    {
        return $this->hasMany(CompanyPaymentMethod::class);
    }
}
