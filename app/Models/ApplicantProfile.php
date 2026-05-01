<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantProfile extends Model
{
    protected $fillable = [
        'user_id',
        'first_name',
        'middle_name',
        'surname',
        'nrc_number',
        'passport_number',
        'email',
        'phone_primary',
        'phone_secondary',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'postal_code',
        'country',
        'identity_document_disk',
        'identity_document_path',
        'identity_document_original_name',
        'identity_document_size_bytes',
        'identity_document_uploaded_at',
    ];

    protected $casts = [
        'identity_document_uploaded_at' => 'datetime',
        'identity_document_size_bytes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

