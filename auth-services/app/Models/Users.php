<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\BusinessProfile;

class Users extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'merchant_id',
        'aes_key',
        'service_type',
        'email',
        'country_code',
        'phone_number',
        'country_name',
        'otp_verified',
        'business_verified',
        'verification_reason',
        'on_trial',
        'trial_calls_remaining',
        'trial_ends_at',
        'role',
        'device_id',
        'session_id',
        'device_timestamp',
        'device',
        'network',
        'sims',
        'location',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function businessProfile()
    {
        return $this->hasOne(BusinessProfile::class, 'user_id');
    }
}
