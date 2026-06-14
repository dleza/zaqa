<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SuperAdminSeeder::class,
            DepartmentsSeeder::class,
            CountriesSeeder::class,
            AwardingInstitutionsSeeder::class,
            // Optional local-dev convenience; no-ops unless UNZA_SIS_LOOKUP_URL is set.
            UnzaInstitutionIntegrationSeeder::class,
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            CertificateSubjectsSeeder::class,
            FeeStructuresSeeder::class,
        ]);
    }
}
