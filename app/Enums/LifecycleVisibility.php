<?php

namespace App\Enums;

enum LifecycleVisibility: string
{
    case Applicant = 'applicant';
    case Internal = 'internal';
    case Both = 'both';
}

