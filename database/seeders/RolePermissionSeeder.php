<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $listPermission = [
            'register-cashier',
            'register-owner',
            'register-company',

            'view-products',
            'create-products',
            'edit-products',
            'delete-products',

            'view-categories',
            'create-categories',
            'edit-categories',
            'delete-categories',

            'view-stocks',
            'create-stocks',
            'edit-stocks',

            'view-customers',
            'create-customers',
            'edit-customers',
            'delete-customers',

            'view-transactions',
            'create-transactions',
            'view-transactions-cashier',

            'create-incomes',
            'create-outcomes',

            'edit-store-profiles',
            'view-store-profiles',

            'create-payment-mmethods',
            'view-payment-methods',
            'view-payment-methods-cashier',
            'edit-payment-methods',
            'delete-payment-methods',

            'create-cashiers',
            'view-cashiers',
            'edit-cashiers',
            'delete-cashiers',

            'view-transaction-reports',
            'view-transaction-details',
            'view-transaction-dailies',

            'view-outcome-reports',

            'view-cashflow-reports',
            'view-cashflow-transaction-detail-reports',
            'view-cashflow-financial-detail-reports',

            'create-shift-cashdrawers', //end
            'finish-shift-cashdrawers', //start
            'view-shift-cashdrawers',
            'view-shift-detail-cashdrawes',
            'view-shift-report',
            'view-shift-detail-report',
            'view-transaction-detail-for-each-shift'
        ];
        foreach ($listPermission as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $superadmin = Role::firstOrCreate(['name' => 'superadmin']);
        $owner = Role::firstOrCreate(['name' => 'owner']);
        $cashier = Role::firstOrCreate(['name' => 'cashier']);

        $superadmin->givePermissionTo(Permission::all());

        $ownerPermissions = [
            'register-cashier',
            'register-owner',
            'register-company',
            'view-categories',
            'create-categories',
            'edit-categories',
            'delete-categories',
            'view-products',
            'create-products',
            'edit-products',
            'delete-products',
            'view-stocks',
            'create-stocks',
            'edit-stocks',
            'view-customers',
            'create-customers',
            'edit-customers',
            'delete-customers',
            'view-transactions',
            'create-transactions',
            'create-incomes',
            'create-outcomes',
            'edit-store-profiles',
            'view-store-profiles',
            'create-payment-mmethods',
            'view-payment-methods',
            'view-payment-methods-cashier',
            'edit-payment-methods',
            'delete-payment-methods',
            'create-cashiers',
            'view-cashiers',
            'edit-cashiers',
            'delete-cashiers',
            'view-transaction-reports',
            'view-transaction-details',
            'view-outcome-reports',
            'view-cashflow-reports',
            'view-cashflow-transaction-detail-reports',
            'view-cashflow-financial-detail-reports',
            'create-shift-cashdrawers', //end
            'finish-shift-cashdrawers', //start
            'view-shift-cashdrawers',
            'view-shift-detail-cashdrawes',
        ];
        $owner->syncPermissions($ownerPermissions);


        $cashierPermissions = [
            'view-products',
            'view-customers',
            'create-customers',
            'view-transactions-cashier',
            'view-payment-methods-cashier',
            'view-transaction-dailies',
            'view-shift-report',
            'view-shift-detail-report',
            'view-transaction-detail-for-each-shift'

        ];
        $cashier->syncPermissions($cashierPermissions);
    }
}
