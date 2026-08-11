<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\BusinessProfile;

class Users extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'users';

    protected static function newFactory()
    {
        return \Database\Factories\UserFactory::new();
    }

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
        'screen_detection',
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
            'device' => 'array',
            'network' => 'array',
            'sims' => 'array',
        ];
    }

    public function businessProfile()
    {
        return $this->hasOne(BusinessProfile::class, 'user_id');
    }

    public function locations()
    {
        return $this->hasMany(Location::class, 'user_id');
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
}
