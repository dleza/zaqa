<?php

namespace App\Enums;

enum QualificationType: string
{
    case SchoolCertificate = 'school_certificate';
    case Certificate = 'certificate';
    case Diploma = 'diploma';
    case Degree = 'degree';
    case Postgraduate = 'postgraduate';
    case Professional = 'professional';
    case Other = 'other';
}

