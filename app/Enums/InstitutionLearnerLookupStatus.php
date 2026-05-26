<?php

namespace App\Enums;

enum InstitutionLearnerLookupStatus: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case InvalidResponse = 'invalid_response';
}

