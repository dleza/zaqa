<?php

namespace Database\Seeders;

use App\Models\AwardingInstitution;
use App\Models\InstitutionIntegration;
use Illuminate\Database\Seeder;

/**
 * Local development convenience seeder for the University of Zambia pull integration.
 *
 * Production and staging integrations MUST be configured through:
 * Admin → Integrations → Institution Pull Integrations
 *
 * This seeder no-ops unless optional local-only env vars are set.
 * It is safe to include in DatabaseSeeder because it does nothing without them.
 */
class UnzaInstitutionIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        $lookupUrl = trim((string) env('UNZA_SIS_LOOKUP_URL', ''));
        if ($lookupUrl === '') {
            return;
        }

        $institution = AwardingInstitution::query()
            ->where('name', 'University of Zambia')
            ->first();

        if (! $institution) {
            return;
        }

        $token = trim((string) env('UNZA_SIS_LOOKUP_TOKEN', ''));

        InstitutionIntegration::query()->updateOrCreate(
            ['awarding_institution_id' => (int) $institution->id],
            [
                'is_active' => true,
                'supports_push' => false,
                'supports_pull' => true,
                'lookup_url' => $lookupUrl,
                'auth_type' => $token !== '' ? 'bearer_token' : 'none',
                'credentials' => $token !== '' ? ['bearer_token' => $token] : null,
                'request_method' => 'POST',
                'timeout_seconds' => 15,
                'retry_attempts' => 2,
                'driver' => 'generic_rest',
            ],
        );
    }
}
