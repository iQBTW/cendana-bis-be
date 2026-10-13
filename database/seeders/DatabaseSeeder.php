<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            CompanySeeder::class,
            PaymentMethodSeeder::class,
            PaymentAccountSeeder::class,
            CompanyPaymentMethodSeeder::class,
            UserSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            CustomerSeeder::class,
            TransactionSeeder::class,
            DetailTransactionSeeder::class,
            HistoryStockSeeder::class,
            CashDrawerSeeder::class,
            FinancialSeeder::class,
            BusinessHealthMasterSeeder::class,
            VisitorSeeder::class,
            HealthRecommendationDictionarySeeder::class,
            SubscriptionPlanSeeder::class,
            VoucherSeeder::class,
            
        ]);
    }
}