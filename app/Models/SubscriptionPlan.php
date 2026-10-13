<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price_monthly',
        'price_yearly',
        'duration_days',
        'is_active',
        'sort_order',
    ];

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(PlanFeature::class, 'plan_feature_items', 'plan_id', 'feature_id')
            ->withPivot('value');
    }

    public function activeFeatures(): BelongsToMany
    {
        return $this->belongsToMany(PlanFeature::class, 'plan_feature_items', 'plan_id', 'feature_id')
            ->where('plan_features.is_active', true)
            ->withPivot('value');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
