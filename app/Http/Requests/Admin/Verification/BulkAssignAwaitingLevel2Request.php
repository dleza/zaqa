<?php

namespace App\Http\Requests\Admin\Verification;

class BulkAssignAwaitingLevel2Request extends BulkAssignAwaitingQualificationsRequest
{
    protected function officerRoleNames(): array
    {
        return [
            'Verification Officer Level 2',
            'Super Admin',
        ];
    }
}
