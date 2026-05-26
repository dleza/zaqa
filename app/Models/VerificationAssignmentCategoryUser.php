<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationAssignmentCategoryUser extends Model
{
    protected $table = 'verification_assignment_category_user';

    protected $fillable = [
        'verification_assignment_category_id',
        'user_id',
        'is_active',
        'is_available',
        'unavailable_reason',
        'unavailable_until',
        'priority',
        'last_assigned_at',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'is_available' => 'bool',
        'unavailable_until' => 'datetime',
        'priority' => 'int',
        'last_assigned_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(VerificationAssignmentCategory::class, 'verification_assignment_category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCurrentlyAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if (! $this->is_available) {
            return false;
        }
        if ($this->unavailable_until && $this->unavailable_until->isFuture()) {
            return false;
        }

        return true;
    }
}

