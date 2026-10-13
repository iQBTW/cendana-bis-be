<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessHealth extends Model
{
    protected $fillable = [
        'period_index',
        'start_date',
        'end_date',
        'report_code',
        'total_sales',
        'total_profit',
        'total_expense',
        'total_hpp',
        'net_profit',
        'total_transactions',
        'profit_margin',
        'health_score',
        'health_category',
        'health_status',
        'narrative',
        'company_id',
    ];
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(BusinessHealthIndicator::class);
    }

    public function recommendations(): BelongsToMany
    {
        return $this->belongsToMany(
            HealthRecommendationDictionary::class,
            'business_health_recommendations',
            'business_health_id',
            'health_dictionary_id'
        )->withTimestamps();
    }
}
