<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PlanFeature extends Model
{
    protected $fillable = [
        'feature_key',
        'feature_name',
        'feature_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(SubscriptionPlan::class, 'plan_feature_items', 'feature_id', 'plan_id')
            ->withPivot('value');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}