<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessHealthIndicator extends Model
{
    protected $fillable = [
        'indicator_score',
        'contribution_point',
        'sub_indicators_data',
        'business_health_id',
        'health_indicator_id',
    ];

    protected $casts = [
        'sub_indicators_data' => 'array',
    ];

    public function businessHealth(): BelongsTo
    {
        return $this->belongsTo(BusinessHealth::class);
    }

    public function healthIndicator(): BelongsTo
    {
        return $this->belongsTo(HealthIndicator::class);
    }
}
