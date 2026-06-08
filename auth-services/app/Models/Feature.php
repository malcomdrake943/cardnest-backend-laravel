<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    use HasFactory;

    protected $table = 'features';

    protected $fillable = [
        'user_id',
        'bank_logo',
        'chip',
        'mag_strip',
        'sig_strip',
        'hologram',
        'customer_service',
        'symmetry',
    ];

    /**
     * Get the user that owns the features.
     */
    public function user()
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}
