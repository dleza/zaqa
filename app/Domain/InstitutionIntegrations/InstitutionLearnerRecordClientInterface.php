<?php

namespace App\Domain\InstitutionIntegrations;

use App\Models\InstitutionIntegration;
use App\Models\Qualification;

interface InstitutionLearnerRecordClientInterface
{
    public function lookup(InstitutionIntegration $integration, Qualification $qualification): InstitutionLearnerLookupResult;
}

