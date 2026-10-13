<?php

namespace App\Services;

use App\Models\SubscriptionPayment;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class MidtransNotificationService
{
    public function handle(array $payload): void
    {
        $this->verifySignature($payload);

        $orderId = $payload['order_id'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus = $payload['fraud_status'] ?? null;

        if (!$orderId || !$transactionStatus) {
            Log::warning('Payload notifikasi Midtrans tidak lengkap', $payload);
            return;
        }
        DB::transaction(function () use ($payload, $orderId, $transactionStatus, $fraudStatus) {
            $payment = SubscriptionPayment::where('order_id', $orderId)
                ->lockForUpdate()
                ->with('subscription')
                ->first();

            if (!$payment) {
                Log::warning('Notifikasi Midtrans untuk order_id yang tidak dikenal', [
                    'order_id' => $orderId,
                ]);
                return;
            }
            if (in_array($payment->status, ['success', 'failed', 'expired', 'cancelled'])) {
                Log::info('Notifikasi diabaikan, payment sudah dalam status final', [
                    'order_id' => $orderId,
                    'current_status' => $payment->status,
                ]);
                return;
            }

            $payment->midtrans_transaction_id = $payload['transaction_id'] ?? null;
            $payment->payment_method = $payload['payment_type'] ?? null;
            $payment->payment_channel = $payload['bank'] ?? ($payload['store'] ?? null);
            $payment->midtrans_response = $payload;

            switch ($transactionStatus) {
                case 'capture':
                    if ($fraudStatus === 'accept') {
                        $this->markAsSuccess($payment);
                    } else {
                        $payment->status = 'pending';
                        $payment->save();
                    }
                    break;

                case 'settlement':
                    $this->markAsSuccess($payment);
                    break;

                case 'pending':
                    $payment->status = 'pending';
                    $payment->save();
                    break;

                case 'deny':
                    $payment->status = 'failed';
                    $payment->save();
                    break;

                case 'cancel':
                    $payment->status = 'cancelled';
                    $payment->save();
                    break;

                case 'expire':
                    $payment->status = 'expired';
                    $payment->save();
                    $payment->subscription?->update(['status' => 'cancelled']);
                    break;

                default:
                    Log::warning('transaction_status Midtrans tidak dikenali', [
                        'order_id' => $orderId,
                        'transaction_status' => $transactionStatus,
                    ]);
            }
        });
    }

    private function verifySignature(array $payload): void
    {
        $orderId = $payload['order_id'] ?? '';
        $statusCode = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';
        $signatureKey = $payload['signature_key'] ?? '';

        $serverKey = config('midtrans.server_key');

        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if (!hash_equals($expectedSignature, $signatureKey)) {
            Log::critical('Signature Midtrans tidak valid - kemungkinan upaya spoofing', [
                'order_id' => $orderId,
            ]);

            throw new Exception('Invalid signature');
        }
    }

    /**
     * Diubah dari private ke public: dipanggil ulang dari SubscriptionPaymentService
     * untuk kasus voucher diskon 100% (tidak lewat Midtrans)
     */
    public function markAsSuccess(SubscriptionPayment $payment): void
    {
        $payment->status = 'success';
        $payment->paid_at = now();
        $payment->save();

        $newSubscription = $payment->subscription;
        if (!$newSubscription) {
            Log::error('Payment sukses tapi subscription terkait tidak ditemukan');
            return;
        }

        $newPlan = $newSubscription->plan;
        $startsAt = now();
        $endsAt = now()->addDays($newPlan->duration_days);

        // Cari apakah ada paket lama yang MASIH AKTIF dan BELUM HABIS
        $oldSubscription = \App\Models\Subscription::where('owner_id', $newSubscription->owner_id)
            ->where('status', 'active')
            ->where('id', '!=', $newSubscription->id)
            ->first();

        if ($oldSubscription && $oldSubscription->ends_at && $oldSubscription->ends_at->isFuture()) {
            $oldPlan = $oldSubscription->plan;

            if ($oldPlan->id === $newPlan->id) {
                // RENEWAL (Paket Sama) -> Lanjutkan dari tanggal kedaluwarsa lama
                $endsAt = $oldSubscription->ends_at->copy()->addDays($newPlan->duration_days);
                
            } elseif ((float) $newPlan->price_monthly > (float) $oldPlan->price_monthly) {
                // UPGRADE (Paket Lebih Mahal) -> Prorate nilai uang
                $remainingDays = now()->diffInDays($oldSubscription->ends_at, false);
                
                if ($remainingDays > 0) {
                    $oldPricePerDay = (float) $oldPlan->price_monthly / max($oldPlan->duration_days, 1);
                    $newPricePerDay = (float) $newPlan->price_monthly / max($newPlan->duration_days, 1);
                    
                    if ($newPricePerDay > 0) {
                        $remainingValue = $remainingDays * $oldPricePerDay;
                        $extraDays = (int) floor($remainingValue / $newPricePerDay);
                        $endsAt = now()->addDays($newPlan->duration_days + $extraDays);
                    }
                }
            }

            // Matikan langganan lama agar tidak ada dua row berstatus 'active'
            $oldSubscription->update(['status' => 'replaced']);
        }

        // Aktifkan langganan baru dengan perhitungan waktu yang sudah final
        $newSubscription->update([
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $newSubscription->owner?->update(['current_subscription_id' => $newSubscription->id]);

        // Catat penggunaan voucher (Audit Trail)
        if ($payment->voucher_id) {
            VoucherUsage::create([
                'voucher_id' => $payment->voucher_id,
                'owner_id' => $newSubscription->owner_id,
                'subscription_payment_id' => $payment->id,
                'used_at' => now(),
            ]);
            Voucher::where('id', $payment->voucher_id)->increment('used_count');
        }

        Log::info('Subscription berhasil diaktifkan/diperbarui', [
            'owner_id' => $newSubscription->owner_id,
            'new_subscription_id' => $newSubscription->id,
            'plan' => $newPlan->name,
            'ends_at' => $endsAt,
        ]);
    }
}