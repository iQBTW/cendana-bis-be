<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPaymentMethod extends Model
{
    protected $table = 'company_payment_methods';

    protected $fillable = [
        'company_id',
        'payment_method_id',
        'status_payment_methods'
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
