<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDrawer extends Model
{
    protected $fillable = [
        'start_shift',
        'end_shift',
        'opening_balance',
        'cash_sales',
        'subtotal',
        'system_balance',
        'actual_balance',
        'difference',
    ];

    public function user()
    {
        return $this->belongsTo(Company::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
