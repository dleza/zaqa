<?php

namespace App\Support\Applications;

use App\Models\Application;

final class ApplicationSubmissionMode
{
    public const STANDARD = 'standard';

    public const INSTITUTIONAL_MULTIPLE = 'institutional_multiple';

    public static function isInstitutionalMultiple(Application $application): bool
    {
        if ((string) ($application->qualification_category ?? '') === self::INSTITUTIONAL_MULTIPLE) {
            return true;
        }

        $meta = $application->metadata;
        if (! is_array($meta) && ! ($meta instanceof \ArrayAccess)) {
            return false;
        }

        return (string) ($meta['submission_mode'] ?? self::STANDARD) === self::INSTITUTIONAL_MULTIPLE;
    }

    public static function resolve(Application $application): string
    {
        return self::isInstitutionalMultiple($application) ? self::INSTITUTIONAL_MULTIPLE : self::STANDARD;
    }
}
