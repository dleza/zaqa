<?php

namespace App\Enums;

enum LearnerRecordMatchStatus: string
{
    case Matched = 'matched';
    case PossibleMatch = 'possible_match';
    case Ambiguous = 'ambiguous';
    case NotFound = 'not_found';
    case Error = 'error';

    public function isTerminal(): bool
    {
        return $this !== self::Error;
    }
}

