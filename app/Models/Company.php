<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'store_name',
        'store_profile',
        'store_address',
        'store_phone',
        'owner_id',
    ];

    public function user()
    {
        return $this->hasMany(User::class, 'company_id', 'id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    public function bankAccount()
    {
        return $this->hasMany(bankAccount::class);
    }

    public function cashDrawer()
    {
        return $this->hasMany(CashDrawer::class);
    }

    public function paymentMethods()
    {
        return $this->belongsToMany(
            PaymentMethod::class,
            'company_payment_methods',
            'company_id',
            'payment_method_id'
        )->withPivot('status_payment_methods');
    }

    public function companyPaymentMethods()
    {
        return $this->hasMany(CompanyPaymentMethod::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'company_id');
    }

    public function financials()
    {
        return $this->hasMany(Financial::class, 'company_id');
    }
}