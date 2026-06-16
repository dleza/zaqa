<?php

namespace App\Enums;

enum LearnerRecordSubmissionSourceType: string
{
    case InstitutionPush = 'institution_push';
    case InstitutionPull = 'institution_pull';
    case AdminImport = 'admin_import';
    case ManualEntry = 'manual_entry';
}
