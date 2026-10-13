<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'transaction_number',
        'transaction_date',
        'subtotal',
        'transaction_discount',
        'transaction_tax',
        'total_transaction',
        'name_other_cost',
        'other_cost',
        'total_payment',
        'change_amount',
        'transaction_description',
        'total_profit',
        'company_id',
        'customer_id',
        'user_id',
        'company_payment_method_id',

    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($transaction) {
            $transactionNumber = self::generateTransactionNumber(
                $transaction->company_id,
                $transaction->id
            );

            $transaction->updateQuietly(['transaction_number' => $transactionNumber]);
        });
    }

    private static function generateTransactionNumber($companyId, $transactionId)
    {
        $date = now()->format('Ymd');
        $paddedId = str_pad($transactionId, 6, '0', STR_PAD_LEFT);

        return "TRX-{$companyId}-{$date}-{$paddedId}";
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(paymentMethod::class);
    }

    public function detailTransaction()
    {
        return $this->hasMany(DetailTransaction::class);
    }

    public function companyPaymentMethod()
    {
        return $this->belongsTo(CompanyPaymentMethod::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function historyStocks()
    {
        return $this->hasMany(HistoryStock::class);
    }
}
