<?php

namespace App\Enums;

enum LifecycleStage: string
{
    case Draft = 'draft';
    case Wizard = 'wizard';
    case Payment = 'payment';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';
    case Review = 'review';
    case SentBack = 'sent_back';
    case Decision = 'decision';
    case Certificate = 'certificate';
    case Closed = 'closed';
}

