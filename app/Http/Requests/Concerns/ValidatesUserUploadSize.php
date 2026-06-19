<?php

namespace App\Http\Requests\Concerns;

use App\Support\Uploads\UserUploadLimit;

trait ValidatesUserUploadSize
{
    protected function userUploadMaxKb(): int
    {
        return UserUploadLimit::maxFileSizeKb();
    }

    /**
     * @return array<string, string>
     */
    protected function userUploadValidationMessages(): array
    {
        return UserUploadLimit::validationMessages();
    }
}
