<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'merchant_id',
        'is_custom_renewal',
        'package_id',
        'api_call_limit',
        'api_calls_used',
        'overage_calls',
        'status',
        'subscription_date',
        'renewal_date'
    ];

    protected $casts = [
        'is_custom_renewal' => 'integer',
        'api_call_limit' => 'integer',
        'api_calls_used' => 'integer',
        'overage_calls' => 'integer',
    ];

    protected $appends = [
        'api_calls_limit',
    ];

    public function getApiCallsLimitAttribute()
    {
        return $this->api_call_limit;
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}
