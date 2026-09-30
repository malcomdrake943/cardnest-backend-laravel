<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceInfo extends Model
{
    protected $fillable = [
        'device_id',
        'merchant_id',
        'session_id',
        'device',
        'network',
        'sims',
        'location',
    ];

    protected $casts = [
        'device' => 'array',
        'network' => 'array',
        'sims' => 'array',
        'location' => 'array',
    ];
}
