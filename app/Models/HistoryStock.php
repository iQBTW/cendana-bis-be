<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoryStock extends Model
{
    protected $fillable = [
        'history_stock_date',
        'initial_stock',
        'stock_in',
        'stock_out',
        'final_stock',
        'stock_description',
        'user_id',
        'product_id',
        'transaction_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
