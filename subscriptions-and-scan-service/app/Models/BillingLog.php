<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingLog extends Model
{
    protected $fillable = [
        'user_id',
        'merchant_id',
        'period_start',
        'period_end',
        'base_calls',
        'overage_calls',
        'overage_charge',
        'is_paid',
        'due_date'
    ];

    protected $casts = [
        'base_calls' => 'integer',
        'overage_calls' => 'integer',
        'overage_charge' => 'float',
        'is_paid' => 'boolean',
    ];
}
