<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

use function Symfony\Component\Clock\now;

class UserSeeder extends Seeder
{
    public function run(): void
    {
         $users = [
            [
                'full_name' => 'Super Admin',
                'email' => 'admin@nusantaratama.com',
                'password' => Hash::make('password'),
                'phone_number' => '081678900567',
                'user_address' => 'Jl. Bungur No.130, Darwo Timur, Gebang, Kec. Patrang, Kabupaten Jember, Jawa Timur 68117',
                'company_id' => null,
                'email_verified_at' => now(),
                'role' => 'superadmin',
            ],
            [
                'full_name' => 'Athar Humam',
                'email' => 'owner@aromanusantara.id',
                'password' => Hash::make('password'),
                'phone_number' => '081212345678',
                'user_address' => 'Jl. Merdeka No. 10, Jember',
                'company_id' => 1,
                'email_verified_at' => now(),
                'role' => 'owner',
            ],
            [
                'full_name' => 'Rizky Saputra',
                'email' => 'cashier1@aromanusantara.id',
                'password' => Hash::make('password'),
                'phone_number' => '081298765432',
                'user_address' => 'Jl. Cempaka No. 45, Jember',
                'company_id' => 1,
                'email_verified_at' => now(),
                'role' => 'cashier',
            ],
            [
                'full_name' => 'Sinta Ayu',
                'email' => 'cashier2@aromanusantara.id',
                'password' => Hash::make('password'),
                'phone_number' => '081355533377',
                'user_address' => 'Jl. Kartini No. 22, Jember',
                'company_id' => 1,
                'email_verified_at' => now(),
                'role' => 'cashier',
            ],
        ];

        foreach ($users as $data) {
            $user = User::create([
                'full_name'     => $data['full_name'],
                'email'         => $data['email'],
                'password'      => $data['password'],
                'phone_number'  => $data['phone_number'],
                'user_address'  => $data['user_address'],
                'company_id'    => $data['company_id'],
                'email_verified_at'    => $data['email_verified_at'],
            ]);

            // Assign role langsung dari field 'role'
            $user->assignRole($data['role']);
        }
    }
}
