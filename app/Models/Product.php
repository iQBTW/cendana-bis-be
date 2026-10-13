<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'product_photo',
        'product_name',
        'product_code',
        'cost_price',
        'selling_price',
        'product_units',
        'product_stock',
        'product_discount',
        'product_description',
        'sales_target_days',
        'category_id',
        'company_id',
    ];

    public function detailTransaction()
    {
        return $this->hasMany(DetailTransaction::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }
    
}
