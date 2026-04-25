<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFeedback extends Model
{
    protected $table = 'service_feedback';

    protected $fillable = [
        'application_id',
        'applicant_user_id',
        'rating_value',
        'rating_label',
        'feedback_text',
        'source',
        'source_step',
        'metadata',
        'submitted_at',
    ];

    protected $casts = [
        'rating_value' => 'int',
        'metadata' => 'array',
        'submitted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }
}

