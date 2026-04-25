<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionProfile extends Model
{
    protected $fillable = [
        'user_id',
        'institution_name',
        'email',
        'phone_primary',
        'phone_secondary',
        'tpin',
        'contact_person_name',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'postal_code',
        'country',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

