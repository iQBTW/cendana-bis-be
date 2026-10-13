<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

class CreateAccessClient extends Command
{
    protected $signature = 'auroapp:access-client
        {name : Nama client}
        {platform : web, android, atau ios}
        {--origin= : Wajib. Web: URL domain (mis. http://localhost:3000). Android/iOS: nama app (mis. auroapp-android)}';

    protected $description = 'Buat access client (password grant) beserta binding-nya';

    public function handle(ClientRepository $clients): int
    {
        $name = $this->argument('name');
        $platform = $this->argument('platform');
        $origin = $this->option('origin') ? trim($this->option('origin')) : null;

        if (!in_array($platform, ['web', 'android', 'ios'], true)) {
            $this->error('Platform harus web, android, atau ios.');
            return self::FAILURE;
        }

        if (!$origin) {
            $this->error('Opsi --origin wajib diisi (web: URL domain, android/ios: nama app).');
            return self::FAILURE;
        }

        if ($platform === 'web') {
            $origin = rtrim($origin, '/');
        } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $origin)) {
            $this->error('Nama app android/ios hanya boleh huruf, angka, titik, strip, dan underscore.');
            return self::FAILURE;
        }

        if (DB::table('oauth_client_bindings')->where('allowed_origin', $origin)->exists()) {
            $this->error("Origin {$origin} sudah dipakai client lain.");
            return self::FAILURE;
        }

        [$client, $plain] = DB::transaction(function () use ($clients, $name, $platform, $origin) {
            $client = $clients->createPasswordGrantClient($name, 'users');

            $plain = Str::random(40);
            $client->forceFill(['secret' => $plain])->save();

            DB::table('oauth_client_bindings')->insert([
                'client_id' => $client->id,
                'platform' => $platform,
                'allowed_origin' => $origin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$client, $plain];
        });

        $this->info('Access client dibuat. Simpan secret sekarang, tidak akan tampil lagi.');
        $this->line("AUROAPP_CLIENT_ID={$client->id}");
        $this->line("AUROAPP_CLIENT_SECRET={$plain}");

        if (in_array($platform, ['android', 'ios'], true)) {
            $this->line("AUROAPP_APP_ORIGIN={$origin}");
        }

        return self::SUCCESS;
    }
}