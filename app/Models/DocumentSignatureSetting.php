<?php

namespace App\Models;

use App\Enums\DocumentSignatureType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignatureSetting extends Model
{
    protected $fillable = [
        'type',
        'display_name',
        'file_path',
        'disk',
        'is_active',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'type' => DocumentSignatureType::class,
        'is_active' => 'bool',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
