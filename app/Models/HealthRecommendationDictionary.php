<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HealthRecommendationDictionary extends Model
{
    protected $fillable = [
        'condition_name',
        'trigger_indicator',
        'alert_risk',
        'insight',
        'user_message',
        'recommendation',
        'suggested_action',
    ];

    public function businessHealths(): BelongsToMany
    {
        return $this->belongsToMany(
            BusinessHealth::class,
            'business_health_recommendations',
            'health_dictionary_id',
            'business_health_id'
        )->withTimestamps();
    }
}
