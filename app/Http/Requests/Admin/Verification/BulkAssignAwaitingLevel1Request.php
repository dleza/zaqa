<?php

namespace App\Http\Requests\Admin\Verification;

class BulkAssignAwaitingLevel1Request extends BulkAssignAwaitingQualificationsRequest
{
    protected function officerRoleNames(): array
    {
        return ['Verification Officer Level 1'];
    }
}
