<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantAdminAuditLog extends Model
{
    protected $table = 'merchant_admin_audit_logs';

    protected $fillable = [
        'merchant_id',
        'performed_by_user_id',
        'target_user_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}

