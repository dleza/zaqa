<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class InstitutionApiClient extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;

    protected $fillable = [
        'awarding_institution_id',
        'name',
        'contact_name',
        'contact_email',
        'scopes',
        'is_active',
        'last_used_at',
        'token_last_sent_at',
        'token_rotated_at',
        'notes',
        'created_by_user_id',
        'revoked_by_user_id',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'is_active' => 'bool',
        'last_used_at' => 'datetime',
        'token_last_sent_at' => 'datetime',
        'token_rotated_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'remember_token',
    ];

    public function awardingInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
