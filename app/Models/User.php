<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    // Sanctum saja di sini. Passport (OAuth2) memakai model OAuthUser.
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    protected $guard_name = 'web';

    protected $fillable = [
        'full_name',
        'email',
        'password',
        'phone_number',
        'user_address',
        'referral_source',
        'remember_token',
        'company_id', // ini relasi untuk kasir
    ];

    protected $hidden = [
        'password',
        'remember_token'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transaction()
    {
        return $this->hasMany(Transaction::class);
    }

    public function financial()
    {
        return $this->hasMany(Financial::class);
    }

    public function cashDrawer()
    {
        return $this->hasMany(CashDrawer::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function companies()
    {
        return $this->hasMany(Company::class, 'owner_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'owner_id');
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscriptions()
                    ->where('status', 'active')
                    ->where('ends_at', '>', now())
                    ->exists();
    }
}
