<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class CleanExpiredToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'token:clean-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menghapus access token dan refresh token (Sanctum) yang sudah kedaluwarsa';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Membersihkan token kedaluwarsa...');

        $refreshDeleted = PersonalAccessToken::where('expires_at', '<', now())
            ->where('name', 'like', '%\\_refresh\\_token')
            ->delete();

        $accessDeleted = PersonalAccessToken::where('expires_at', '<', now())->delete();

        $this->info("Access token dihapus: {$accessDeleted}");
        $this->info("Refresh token dihapus: {$refreshDeleted}");

        return Command::SUCCESS;
    }
}
