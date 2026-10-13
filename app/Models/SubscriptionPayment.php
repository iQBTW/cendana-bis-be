<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'subscription_id',
        'voucher_id', 
        'order_id',
        'midtrans_transaction_id',
        'gross_amount',
        'original_amount',
        'discount_amount',
        'payment_method',
        'payment_channel',
        'status',
        'midtrans_ok,response', 
        'paid_at',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'midtrans_response' => 'array', // Otomatis convert JSON string ke PHP Array
        'paid_at' => 'datetime',
    ];

    /**
     * Relasi balik ke entitas data langganan
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}