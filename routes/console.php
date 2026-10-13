<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\{Artisan, Schedule};
use App\Models\BusinessHealth;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('token:clean-expired')->dailyAt('00:00');
Schedule::command('business-health:generate')->dailyAt('00:00');
Schedule::command('gsc:visitor-date')->dailyAt('00:00');
Schedule::command('gsc:visitor-country')->dailyAt('00:00');
Schedule::command('subscriptions:update-statuses')->dailyAt('00:00');
Schedule::command('auth:clean-reset-tokens')->daily();
