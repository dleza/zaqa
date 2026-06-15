<?php

namespace App\Models;

use App\Enums\LearnerRecordSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearnerRecord extends Model
{
    protected $fillable = [
        'awarding_institution_id',
        'import_id',
        'institution_name_raw',
        'student_id',
        'certificate_no',
        'nrc_number',
        'passport_no',
        'first_name',
        'last_name',
        'other_names',
        'gender',
        'program_of_study',
        'qualification_title_normalized',
        'year_awarded',
        'award_date',
        'classification',
        'source_type',
        'source_reference',
        'raw_payload',
        'nrc_normalized',
        'passport_normalized',
        'name_normalized',
        'student_id_normalized',
        'certificate_no_normalized',
        'dedupe_hash',
        'is_active',
        'verified_at',
    ];

    protected $casts = [
        'source_type' => LearnerRecordSourceType::class,
        'year_awarded' => 'int',
        'award_date' => 'date',
        'raw_payload' => 'array',
        'is_active' => 'bool',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function awardingInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(LearnerRecordImport::class, 'import_id');
    }

    public function matchAttempts(): HasMany
    {
        return $this->hasMany(LearnerRecordMatchAttempt::class);
    }
}

