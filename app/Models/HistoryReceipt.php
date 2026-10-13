<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoryReceipt extends Model
{
     protected $table = 'history_receipt';
     
    protected $fillable = [
        'kode_transaksi',
        'tanggal',
        'waktu',
        'pelanggan',
        'kasir',
        'metode_pembayaran',
        'total_penjualan',
        'diskon',
        'biaya_lain',
        'bayar',
        'kembalian',
        'catatan',
        'products',
    ];

    protected $casts = [
        'products' => 'array',
    ];
}
