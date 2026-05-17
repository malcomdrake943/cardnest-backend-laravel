<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanSession extends Model
{
    protected $fillable = [
        'scan_id',
        'merchant_id',
        'device_type',
        'tries',
        'encryption_key',
        'encrypted_data',
        'scanned_at',
    ];

    public function scan()
    {
        return $this->hasMany(Scan::class);
    }
}
