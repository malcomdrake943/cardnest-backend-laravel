<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\BusinessProfile;
use App\Support\MerchantPermissions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'parent_merchant_id',
        'is_additional_admin',
        'access_type',
        'permissions',
        'admin_status',
        'tokens_revoked_at',
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
     * Accessors appended when serializing user records.
     *
     * @var list<string>
     */
    protected $appends = [
        'phone_no',
        'status',
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
            'is_additional_admin' => 'boolean',
            'permissions' => 'array',
            'tokens_revoked_at' => 'datetime',
        ];
    }

    public function parentMerchant(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'parent_merchant_id');
    }

    public function additionalAdmins(): HasMany
    {
        return $this->hasMany(Users::class, 'parent_merchant_id')
            ->where('is_additional_admin', true);
    }

    public function getPhoneNoAttribute(): ?string
    {
        return $this->phone_number;
    }

    public function getStatusAttribute(): string
    {
        return $this->admin_status ?? 'active';
    }

    /**
     * Resolve the primary merchant user ID for tenant-scoped queries.
     */
    public function getEffectiveMerchantUserId(): int
    {
        if ($this->is_additional_admin && $this->parent_merchant_id) {
            return (int) $this->parent_merchant_id;
        }

        return (int) $this->id;
    }

    /**
     * Resolve the effective merchant_id string (parent's merchant_id for additional admins).
     */
    public function getEffectiveMerchantId(): ?string
    {
        if ($this->is_additional_admin && $this->parent_merchant_id) {
            return $this->parentMerchant?->merchant_id;
        }

        return $this->merchant_id;
    }

    /**
     * Resolve effective business_verified status (inherited from primary merchant for additional admins).
     */
    public function getEffectiveBusinessVerified(): ?string
    {
        if ($this->is_additional_admin && $this->parent_merchant_id) {
            return $this->parentMerchant?->business_verified;
        }

        return $this->business_verified;
    }

    /**
     * Resolve the effective list of merchant permissions for this user.
     *
     * @return list<string>
     */
    public function getEffectivePermissions(): array
    {
        if (!$this->is_additional_admin || $this->access_type === 'full') {
            return MerchantPermissions::all();
        }

        return MerchantPermissions::resolvePermissions('custom', $this->permissions);
    }

    /**
     * Determine if the user has at least one of the required merchant permissions.
     *
     * @param  string|array<int, string>  $permissions
     */
    public function hasMerchantPermission(string|array $permissions): bool
    {
        if (!$this->is_additional_admin || $this->role === 'SUPER_ADMIN' || $this->access_type === 'full') {
            return true;
        }

        $required = is_array($permissions) ? $permissions : [$permissions];
        $granted = $this->getEffectivePermissions();

        return count(array_intersect($required, $granted)) > 0;
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
