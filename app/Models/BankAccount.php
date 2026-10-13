<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $fillable = [
        'bank_name',
        'account_number',
        'account_holder',

    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
      public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
