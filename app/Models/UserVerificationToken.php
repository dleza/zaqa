<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVerificationToken extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'token_hash',
        'sent_to',
        'expires_at',
        'used_at',
        'attempt_count',
        'resent_count',
        'last_sent_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'attempt_count' => 'int',
        'resent_count' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

