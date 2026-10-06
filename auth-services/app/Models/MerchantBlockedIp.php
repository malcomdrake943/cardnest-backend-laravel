<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantBlockedIp extends Model
{
    protected $fillable = [
        'merchant_id',
        'ip_address',
        'reason',
    ];
}
