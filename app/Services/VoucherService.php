<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\VoucherUsage;
use Exception;

class VoucherService
{
    /**
     * Validasi kode voucher untuk owner & paket tertentu, lalu hitung diskonnya.
     * @return array{voucher_id: int, code: string, discount_amount: float, final_amount: float}
     * @throws Exception dengan message yang aman ditampilkan ke user
     */
    public function validateAndCalculate(string $code, int $ownerId, float $planPrice): array
    {
        $voucher = Voucher::where('code', $code)->first();

        if (!$voucher) {
            throw new Exception('Kode voucher tidak ditemukan.');
        }

        if (!$voucher->is_active) {
            throw new Exception('Voucher ini sudah tidak aktif.');
        }

        $now = now();
        if ($now->lt($voucher->starts_at) || $now->gt($voucher->expires_at)) {
            throw new Exception('Voucher sudah tidak berlaku atau belum dimulai.');
        }

        if ($voucher->max_usage !== null && $voucher->used_count >= $voucher->max_usage) {
            throw new Exception('Kuota pemakaian voucher ini sudah habis.');
        }

        $usageByUser = VoucherUsage::where('voucher_id', $voucher->id)
            ->where('owner_id', $ownerId)
            ->count();

        if ($usageByUser >= $voucher->max_usage_per_user) {
            throw new Exception('Anda sudah pernah menggunakan voucher ini.');
        }

        if ($voucher->min_purchase_amount !== null && $planPrice < $voucher->min_purchase_amount) {
            throw new Exception('Paket ini belum memenuhi minimum pembelian untuk voucher ini.');
        }

        $discountAmount = $this->calculateDiscount($voucher, $planPrice);

        return [
            'voucher_id' => $voucher->id,
            'code' => $voucher->code,
            'discount_amount' => $discountAmount,
            'final_amount' => max($planPrice - $discountAmount, 0),
        ];
    }

    private function calculateDiscount(Voucher $voucher, float $planPrice): float
    {
        if ($voucher->discount_type === 'fixed') {
            return min((float) $voucher->discount_value, $planPrice);
        }

        // percentage
        $discount = $planPrice * ((float) $voucher->discount_value / 100);

        if ($voucher->max_discount_amount !== null) {
            $discount = min($discount, (float) $voucher->max_discount_amount);
        }

        return $discount;
    }
}