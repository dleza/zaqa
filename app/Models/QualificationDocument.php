<?php

namespace App\Models;

use App\Domain\Documents\QualificationDocumentEvidence;
use App\Enums\DocumentType;
use App\Enums\DocumentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationDocument extends Model
{
    protected $fillable = [
        'application_id',
        'qualification_id',
        'document_type',
        'original_name',
        'stored_name',
        'disk',
        'path',
        'mime_type',
        'extension',
        'size_bytes',
        'sha256_hash',
        'visibility',
        'uploaded_by_user_id',
        'version_number',
        'is_current_version',
        'superseded_at',
        'deleted_at',
        'replaced_by_document_id',
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'visibility' => DocumentVisibility::class,
        'size_bytes' => 'int',
        'version_number' => 'int',
        'is_current_version' => 'bool',
        'superseded_at' => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @param  Builder<QualificationDocument>  $query
     * @return Builder<QualificationDocument>
     */
    public function scopeActiveEvidence(Builder $query): Builder
    {
        return QualificationDocumentEvidence::applyActiveEvidenceScope($query);
    }

    public function isActiveEvidence(): bool
    {
        return QualificationDocumentEvidence::isActiveEvidence($this);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_document_id');
    }
}

