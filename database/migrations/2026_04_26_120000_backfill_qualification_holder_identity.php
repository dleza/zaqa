<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill missing/blank holder fields on existing qualifications from application metadata.
        // We only fill blanks; we do not overwrite already captured values.

        // Holder name
        DB::statement(<<<'SQL'
            UPDATE qualifications q
            INNER JOIN applications a ON a.id = q.application_id
            SET q.qualification_holder_name =
                NULLIF(TRIM(
                    JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.verification_subject.full_name'))
                ), '')
            WHERE (q.qualification_holder_name IS NULL OR TRIM(q.qualification_holder_name) = '')
              AND JSON_EXTRACT(a.metadata, '$.verification_subject.full_name') IS NOT NULL
        SQL);

        // NRC/Passport number
        DB::statement(<<<'SQL'
            UPDATE qualifications q
            INNER JOIN applications a ON a.id = q.application_id
            SET q.nrc_passport_number =
                NULLIF(TRIM(
                    COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.verification_subject.nrc_number')),
                        JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.verification_subject.passport_number'))
                    )
                ), '')
            WHERE (q.nrc_passport_number IS NULL OR TRIM(q.nrc_passport_number) = '')
              AND (
                JSON_EXTRACT(a.metadata, '$.verification_subject.nrc_number') IS NOT NULL
                OR JSON_EXTRACT(a.metadata, '$.verification_subject.passport_number') IS NOT NULL
              )
        SQL);
    }

    public function down(): void
    {
        // Non-destructive backfill; nothing to rollback.
    }
};

