<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    protected $fillable = [
        'user_id',
        'merchant_id',
        'merchant_key',
        'card_number_masked',
        'status',
        'encrypted_data',
        'scan_id',
        'session_id',
        'failure_reason',
        'failure_stage',
    ];


    public function session()
    {
        return $this->belongsTo(ScanSession::class);
    }
}
