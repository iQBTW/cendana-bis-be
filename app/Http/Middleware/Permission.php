<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseFormatter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Permission
{
    private const SUPERADMIN = 'superadmin';
    private const OWNER = 'owner';
    private const CASHIER = 'cashier';

    public static function listAccess(): array
    {
        return [

            // Produk
            'products'      => [self::SUPERADMIN, self::OWNER],
            'products.read' => [self::SUPERADMIN, self::OWNER, self::CASHIER],
            'history-stock' => [self::SUPERADMIN, self::OWNER],

            // Master data
            'categories' => [self::SUPERADMIN, self::OWNER],

            // Customer
            'customers'      => [self::SUPERADMIN, self::OWNER, self::CASHIER],
            'customers.read' => [self::SUPERADMIN, self::OWNER, self::CASHIER],

            'financials'           => [self::SUPERADMIN, self::OWNER],
            'payment-methods'      => [self::SUPERADMIN, self::OWNER],
            'payment-methods.read' => [self::SUPERADMIN, self::OWNER, self::CASHIER],

            // User management
            'users.read'       => [self::SUPERADMIN, self::OWNER],
            'users.manage'     => [self::OWNER],
            'register-cashier' => [self::OWNER],

            // Reports (owner)
            'transaction-reports'  => [self::OWNER],
            'outcome-reports'      => [self::OWNER],
            'cashflow-reports'     => [self::OWNER],
            'business-health'      => [self::OWNER],
            'business-performance' => [self::OWNER],

            // Reports (superadmin)
            'transaction-reports.admin' => [self::SUPERADMIN],
            'business-health.admin'     => [self::SUPERADMIN],

            // Dashboard per role
            'dashboard.owner'   => [self::OWNER],
            'dashboard.admin'   => [self::SUPERADMIN],
            'dashboard.cashier' => [self::CASHIER],

            // Profile & company
            'profile'          => [self::SUPERADMIN, self::OWNER, self::CASHIER],
            'has-company'      => [self::SUPERADMIN, self::OWNER, self::CASHIER],
            'companies'        => [self::SUPERADMIN],
            'companies.create' => [self::OWNER],
            'companies.owner'  => [self::OWNER],

            // Transaksi
            'transactions.create' => [self::OWNER, self::CASHIER],
            'transactions.read'   => [self::OWNER],
            'cashier.transaction' => [self::CASHIER],
            'history-receipts'    => [self::OWNER, self::CASHIER],

            // Subscription & billing (superadmin only)
            'plan-features'           => [self::SUPERADMIN],
            'subscription-plans'      => [self::SUPERADMIN],
            'subscription-plans.read' => [self::SUPERADMIN, self::OWNER],
            'vouchers'                => [self::SUPERADMIN],
            'vouchers.apply'          => [self::OWNER],
            'subscriptions.admin'     => [self::SUPERADMIN],

            // Subscription (owner, checkout & lihat punya sendiri)
            'subscriptions.owner' => [self::OWNER],

            // Subscription Payment
            'subscription-payments.owner'  => [self::OWNER],
            'subscription-payments.detail' => [self::OWNER, self::SUPERADMIN],
            'subscription-payments.admin'  => [self::SUPERADMIN],

            // Kelola frontend client (X-Client-Id / X-Client-Secret)
            'frontends' => [self::SUPERADMIN],
        ];
    }

    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = auth()->user();

        if (!$user) {
            return ResponseFormatter::error(null, 'anda belum login!', 401);
        }

        $access = static::listAccess();

        // Nama permission salah ketik/belum didaftarkan: tolak dan catat di log,
        // supaya tidak terlihat seperti masalah role.
        if (!array_key_exists($permission, $access)) {
            Log::warning("Permission '{$permission}' belum didaftarkan di Permission::listAccess()");

            return ResponseFormatter::error(null, 'anda tidak memiliki akses!', 403);
        }

        if (!$user->hasAnyRole($access[$permission])) {
            return ResponseFormatter::error(null, 'anda tidak memiliki akses!', 403);
        }

        return $next($request);
    }
}