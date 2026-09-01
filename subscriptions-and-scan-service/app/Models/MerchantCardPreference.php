<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantCardPreference extends Model
{
    protected $fillable = [
        'merchant_id',
        'card_types',
        'card_networks',
        'blocked_countries',
    ];

    protected $casts = [
        'card_types' => 'array',
        'card_networks' => 'array',
        'blocked_countries' => 'array',
    ];
}
