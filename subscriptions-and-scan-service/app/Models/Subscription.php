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

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}
