<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationCertificate extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_REISSUED = 'reissued';

    public const STATUS_REVOKED = 'revoked';

    public const TYPE_VERIFICATION = 'verification';

    public const TYPE_REJECTION = 'rejection';

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
        'certificate_type',
        'metadata',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
        'revocation_public_note',
        'replaces_certificate_id',
        'superseded_by_certificate_id',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
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

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function replacesCertificate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_certificate_id');
    }

    public function supersededByCertificate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_certificate_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVerification(Builder $query): Builder
    {
        return $query->where('certificate_type', self::TYPE_VERIFICATION);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRejection(Builder $query): Builder
    {
        return $query->where('certificate_type', self::TYPE_REJECTION);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REVOKED);
    }

    public function isPubliclyValid(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function isVerificationCertificate(): bool
    {
        return ($this->certificate_type ?: self::TYPE_VERIFICATION) === self::TYPE_VERIFICATION;
    }

    public function isRejectionCertificate(): bool
    {
        return $this->certificate_type === self::TYPE_REJECTION;
    }
}
