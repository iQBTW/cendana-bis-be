<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Midtrans\Config as MidtransConfig;
use Midtrans\Snap;
use Exception;

class SubscriptionPaymentService
{
    public function __construct(
        protected MidtransNotificationService $notificationService
    )
    {
        MidtransConfig::$serverKey = config('midtrans.server_key');
        MidtransConfig::$isProduction = config('midtrans.is_production');
        MidtransConfig::$isSanitized = config('midtrans.is_sanitized');
        MidtransConfig::$is3ds = config('midtrans.is_3ds');
    }

    /**
     * Membuat subscription (status pending) + payment record + transaksi Snap Midtrans.
     * Dipanggil saat owner klik "Bayar Sekarang" di halaman konfirmasi.
     *
     * Kalau voucher membuat total transaksi jadi 0 (diskon 100%), Midtrans TIDAK dipanggil
     * (Midtrans menolak gross_amount = 0) — subscription & payment tetap dibuat seperti alur
     * normal (status pending), lalu langsung "diselesaikan" lewat
     * MidtransNotificationService::markAsSuccess() yang sama persis dipakai webhook Midtrans,
     * supaya logic replace/renewal/upgrade/prorate & pencatatan VoucherUsage konsisten
     * dan tidak ada duplikasi logic / dobel pencatatan.
     *
     * @param  int  $ownerId   ID owner yang sedang login
     * @param  int  $planId
     * @return array{snap_token: ?string, redirect_url: ?string, order_id: string, requires_payment: bool, status?: string}
     */
    public function createTransaction(int $ownerId, int $planId, ?string $voucherCode = null): array
    {
        $newPlan = SubscriptionPlan::where('is_active', true)->findOrFail($planId);

        // 1. Cek apakah ada langganan aktif untuk validasi DOWNGRADE
        $activeSubscription = Subscription::where('owner_id', $ownerId)
            ->where('status', 'active')
            ->first();

        if ($activeSubscription && $activeSubscription->ends_at && $activeSubscription->ends_at->isFuture()) {
            $currentPlan = $activeSubscription->plan;

            // Validasi: Tolak jika paket baru lebih murah (Downgrade)
            if ((float) $newPlan->price_monthly < (float) $currentPlan->price_monthly) {
                throw new Exception(
                    "Anda masih berlangganan paket {$currentPlan->name} hingga "
                    . $activeSubscription->ends_at->translatedFormat('d M Y')
                    . '. Penurunan paket hanya bisa dilakukan setelah paket saat ini berakhir.'
                );
            }
        }

        DB::beginTransaction();
        try {
        $originalAmount = (float) $newPlan->price_monthly;
        $discountAmount = 0;
        $voucherId = null;

        if ($voucherCode) {
            $voucherResult = app(VoucherService::class)->validateAndCalculate($voucherCode, $ownerId, $originalAmount);
            $discountAmount = $voucherResult['discount_amount'];
            $voucherId = $voucherResult['voucher_id'];
        }

        $grossAmount = (int) max($originalAmount - $discountAmount, 0);

        // Lama: kalau grossAmount <= 0 langsung dianggap error.
        // Padahal ini kasus valid (voucher diskon 100%) — Midtrans menolak gross_amount 0,
        // jadi bukan bug voucher, tapi keterbatasan payment gateway.
        // if ($grossAmount <= 0) {
        //     throw new Exception('Nominal transaksi tidak valid. Silakan hubungi admin.');
        // }

        $orderId = $this->generateOrderId();

        // Ambil data customer untuk dikirim ke Midtrans (wajib ada sebelum $snapParams dibuat)
        $customerDetails = $this->resolveCustomerDetails($ownerId);

        // 3. SELALU buat baris pending baru. Jangan menimpa status active yang sedang berjalan!
        $subscription = Subscription::create([
            'owner_id' => $ownerId,
            'plan_id' => $newPlan->id,
            'status' => 'pending',
            'starts_at' => null,
            'ends_at' => null,
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'order_id' => $orderId,
            'gross_amount' => $grossAmount,
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'voucher_id' => $voucherId,
            'payment_method' => $grossAmount <= 0 ? 'voucher full discount' : null,
            'status' => 'pending',
        ]);

        // Midtrans tidak menerima transaksi dengan gross_amount 0, jadi skip Midtrans sepenuhnya.
        if ($grossAmount <= 0) {
            $payment->load('subscription');
            $this->notificationService->markAsSuccess($payment);

            DB::commit();

            return [
                'snap_token' => null,
                'redirect_url' => null,
                'order_id' => $orderId,
                'requires_payment' => false,
                'status' => 'success',
            ];
        }

        $snapParams = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $grossAmount,
            ],
            'item_details' => [[
                'id' => 'plan-' . $newPlan->id,
                'price' => $grossAmount,
                'quantity' => 1,
                'name' => 'Paket Langganan ' . $newPlan->name,
            ]],
            'customer_details' => $customerDetails,
            'callbacks' => [
                'finish' => config('app.frontend_url') . '/premium/checkout/finish',
            ],
        ];
 
            $snapToken = Snap::getSnapToken($snapParams);
            
            DB::commit();
            return [
            'snap_token' => $snapToken,
            'redirect_url' => config('midtrans.is_production')
                ? "https://app.midtrans.com/snap/v2/vtweb/{$snapToken}"
                : "https://app.sandbox.midtrans.com/snap/v2/vtweb/{$snapToken}",
            'order_id' => $orderId,
            'requires_payment' => true,
        ];
        } catch (Exception $e) {
            DB::rollback();
            Log::error('Midtrans Snap token gagal dibuat', [
                'order_id' => $orderId ?? null,
                'message' => $e->getMessage(),
            
            ]);
            throw new Exception('Gagal membuat transaksi pembayaran. Silakan coba lagi.');
        }
 
        
    }

    private function generateOrderId(): string
    {
        return 'SUB-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));
    }

    /**
     * Ambil data customer untuk dikirim ke Midtrans.
     * Sesuaikan dengan struktur model Owner/User yang ada di AURO.
     *
     * @throws Exception jika user tidak ditemukan atau data belum lengkap
     */
    private function resolveCustomerDetails(int $ownerId): array
    {
        $owner = User::find($ownerId);

        if (!$owner) {
            throw new Exception('Data pengguna tidak ditemukan. Silakan login ulang.');
        }

        if (empty($owner->full_name) || empty($owner->email) || empty($owner->phone_number)) {
            throw new Exception('Profil Anda belum lengkap. Mohon lengkapi nama, email, dan nomor HP sebelum melanjutkan pembayaran.');
        }

        return [
            'first_name' => $owner->full_name,
            'email'      => $owner->email,
            'phone'      => $owner->phone_number,
            'address'    => $owner->user_address ?? '-',
        ];
    }

    /**
     * Menerjemahkan pesan error mentah dari Midtrans menjadi pesan
     * yang lebih jelas dan aman untuk ditampilkan ke user.
     */
    private function mapMidtransErrorMessage(string $errorMessage): string
    {
        $lower = strtolower($errorMessage);

        return match (true) {
            str_contains($lower, '401') || str_contains($lower, 'unauthorized')
                => 'Konfigurasi pembayaran bermasalah. Silakan hubungi admin.',

            str_contains($lower, 'customer_details') ||
            str_contains($lower, 'email') ||
            str_contains($lower, 'phone')
                => 'Data profil Anda belum lengkap atau tidak valid (nama/email/nomor HP). Silakan lengkapi profil terlebih dahulu.',

            str_contains($lower, 'gross_amount') || str_contains($lower, 'amount')
                => 'Terjadi kesalahan pada nominal transaksi. Silakan coba lagi atau hubungi admin.',

            str_contains($lower, 'order_id') || str_contains($lower, 'duplicate')
                => 'Transaksi dengan ID yang sama sudah ada. Silakan coba lagi.',

            str_contains($lower, 'timeout') || str_contains($lower, 'connection')
                => 'Koneksi ke layanan pembayaran bermasalah. Silakan coba lagi dalam beberapa saat.',

            default
                => 'Gagal membuat transaksi pembayaran. Silakan coba lagi beberapa saat lagi.',
        };
    }

    /**
     * Mengecek status pembayaran berdasarkan order_id.
     * Dipakai endpoint polling dari frontend setelah Snap popup ditutup.
     */
    public function getPaymentStatus(string $orderId): SubscriptionPayment
    {
        return SubscriptionPayment::where('order_id', $orderId)
            ->with('subscription')
            ->firstOrFail();
    }
}