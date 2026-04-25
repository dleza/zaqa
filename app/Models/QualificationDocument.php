<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\DocumentVisibility;
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
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'visibility' => DocumentVisibility::class,
        'size_bytes' => 'int',
        'version_number' => 'int',
        'is_current_version' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

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
}

