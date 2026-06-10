<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Users;

class Location extends Model
{
    use HasFactory;

    protected $table = 'locations';

    protected $fillable = [
        'user_id',
        'merchant_id',
        'lat',
        'lon',
        'address',
    ];

    public function user()
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}
