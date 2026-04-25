<?php

namespace Database\Seeders;

use App\Models\BillingCategory;
use Illuminate\Database\Seeder;

class BillingCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'code' => 'LOCAL_GENERAL_EDU',
                'name' => 'Local General Education (Grade 7, 9, 12)',
                'description' => 'Primary and secondary school certification checks.',
                'local_processing_days' => 14,
                'foreign_processing_days' => 60,
                'sort_order' => 10,
            ],
            [
                'code' => 'LOCAL_CERTS_DIPLOMAS',
                'name' => 'Local Certificates & Diplomas',
                'description' => 'Certificates, higher certificates, technical certificates, diplomas.',
                'local_processing_days' => 14,
                'foreign_processing_days' => 60,
                'sort_order' => 20,
            ],
            [
                'code' => 'LOCAL_DEGREES',
                'name' => "Local Degrees (Bachelor's, Master's, PhD)",
                'description' => 'Degree-level checks and higher.',
                'local_processing_days' => 14,
                'foreign_processing_days' => 60,
                'sort_order' => 30,
            ],
            [
                'code' => 'FOREIGN_QUALIFICATIONS',
                'name' => 'Foreign Qualifications (All levels)',
                'description' => 'Foreign applications billed via foreign pricing path across all levels.',
                'local_processing_days' => 14,
                'foreign_processing_days' => 60,
                'sort_order' => 40,
            ],
        ];

        foreach ($rows as $row) {
            BillingCategory::query()->updateOrCreate(
                ['code' => $row['code']],
                array_merge($row, ['is_active' => true]),
            );
        }
    }
}

