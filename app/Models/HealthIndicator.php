<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class HealthIndicator extends Model
{
    protected $fillable = [
        'indicator_code',
        'indicator_name',
        'weight',
        'description',
    ];

    public function businessHealthIndicators(): HasMany
    {
        return $this->hasMany(BusinessHealthIndicator::class);
    }
}
