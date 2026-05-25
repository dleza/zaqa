<?php

namespace App\Enums;

enum QualificationTitleSource: string
{
    case Catalog = 'catalog';
    case Other = 'other';
    case AutoVerifiedInternal = 'auto_verified_internal';
    case InstitutionApi = 'institution_api';
    case ManualOverride = 'manual_override';
}

