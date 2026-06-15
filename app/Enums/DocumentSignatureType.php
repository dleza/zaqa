<?php

namespace App\Enums;

enum DocumentSignatureType: string
{
    case Certificate = 'certificate';
    case Receipt = 'receipt';
}
