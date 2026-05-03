<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationCertificate extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_REISSUED = 'reissued';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'qualification_id',
        'application_id',
        'certificate_number',
        'zaqa_reference_number',
        'verification_token',
        'file_path',
        'issued_by_user_id',
        'issued_at',
        'recipient_email',
        'status',
        'metadata',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
