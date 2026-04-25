<?php

namespace App\Enums;

enum ConsentType: string
{
    case LocalEmbedded = 'local_embedded';
    case ForeignUploaded = 'foreign_uploaded';
}

