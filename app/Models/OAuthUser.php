<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * Model KHUSUS Passport (OAuth2), memakai tabel `users` yang sama dengan User.
 *
 * Kenapa terpisah: kontrak OAuthenticatable mewajibkan tokens(): HasMany dan
 * createToken(): PersonalAccessTokenResult, sedangkan trait Sanctum di User
 * memakai tokens(): MorphMany dan createToken(): NewAccessToken. Dua kontrak itu
 * saling bertabrakan, jadi tidak bisa hidup di satu class.
 *
 * Jangan `extends User`: property $accessToken akan bentrok lagi.
 */
class OAuthUser extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Ambil model User utama (untuk relasi company, subscription, role, dll). */
    public function toUser(): User
    {
        return User::findOrFail($this->getKey());
    }
}