<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'actor_name_snapshot',
        'event_type',
        'module',
        'entity_type',
        'entity_id',
        'action_name',
        'message',
        'before_state',
        'after_state',
        'metadata',
        'ip_address',
        'user_agent',
        'correlation_id',
    ];

    protected $casts = [
        'before_state' => AsArrayObject::class,
        'after_state' => AsArrayObject::class,
        'metadata' => AsArrayObject::class,
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

