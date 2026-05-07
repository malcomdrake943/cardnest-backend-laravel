<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $table = "business_profiles";
    protected $fillable = [
        'user_id',
        'service_type',
        'account_holder_id',
        'email',
        'business_name',
        'business_registration_number',
        'street',
        'street_line2',
        'city',
        'state',
        'zip_code',
        'country',
        'registration_document_path',
    ];

    public function accountHolder()
    {
        return $this->belongsTo(AccountHolder::class, 'account_holder_id');
    }

    public function user()
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}
