<?php

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Matikan route bawaan /oauth/*, login memakai /api/v1/login
        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        // Token dari createToken() berlaku 7 hari
        Passport::personalAccessTokensExpireIn(now()->addWeek());

        if (!app()->isLocal()) {
            URL::forceScheme('https');
        }
    }
}
