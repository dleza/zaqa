<?php

namespace App\Models;

use App\Enums\ConsentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentForm extends Model
{
    protected $fillable = [
        'application_id',
        'consent_type',
        'embedded_text_version',
        'agreed_by_name',
        'agreed_at',
        'uploaded_document_id',
        'zaqa_uploaded_document_id',
        'source_awarding_body_name',
    ];

    protected $casts = [
        'consent_type' => ConsentType::class,
        'agreed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function uploadedDocument(): BelongsTo
    {
        return $this->belongsTo(QualificationDocument::class, 'uploaded_document_id');
    }

    public function zaqaUploadedDocument(): BelongsTo
    {
        return $this->belongsTo(QualificationDocument::class, 'zaqa_uploaded_document_id');
    }
}

