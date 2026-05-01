<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationSubjectResult extends Model
{
    protected $fillable = [
        'qualification_id',
        'certificate_subject_id',
        'subject_name',
        'grade',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'int',
    ];

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    public function certificateSubject(): BelongsTo
    {
        return $this->belongsTo(CertificateSubject::class);
    }
}

