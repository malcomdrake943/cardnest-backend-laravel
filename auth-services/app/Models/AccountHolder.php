<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountHolder extends Model
{
    protected $fillable = [
        'id_type',
        'id_number',
        'first_name',
        'last_name',
        'email',
        'date_of_birth',
        'street',
        'street_line2',
        'city',
        'state',
        'zip_code',
        'country',
        'id_document_path',
    ];
}
