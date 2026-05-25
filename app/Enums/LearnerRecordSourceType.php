<?php

namespace App\Enums;

enum LearnerRecordSourceType: string
{
    case Import = 'import';
    case InstitutionApi = 'institution_api';
    case Manual = 'manual';
}

