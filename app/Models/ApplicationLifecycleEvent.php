<?php

namespace App\Models;

use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationLifecycleEvent extends Model
{
    protected $fillable = [
        'application_id',
        'event_type',
        'event_code',
        'stage',
        'status_snapshot',
        'title',
        'description',
        'actor_user_id',
        'actor_name_snapshot',
        'actor_role',
        'visibility',
        'comment',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'stage' => LifecycleStage::class,
        'visibility' => LifecycleVisibility::class,
        'metadata' => AsArrayObject::class,
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

