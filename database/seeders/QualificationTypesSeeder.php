<?php

namespace Database\Seeders;

use App\Models\BillingCategory;
use App\Models\QualificationType;
use Illuminate\Database\Seeder;

class QualificationTypesSeeder extends Seeder
{
    public function run(): void
    {
        $catGeneral = BillingCategory::query()->where('code', 'LOCAL_GENERAL_EDU')->firstOrFail();
        $catCerts = BillingCategory::query()->where('code', 'LOCAL_CERTS_DIPLOMAS')->firstOrFail();
        $catDegrees = BillingCategory::query()->where('code', 'LOCAL_DEGREES')->firstOrFail();

        $rows = [
            // Local General Education
            ['zqf_level_code' => 'L1', 'level_label' => 'Level 1', 'name' => 'Primary Education Certificate (Grade 7)', 'billing_category_id' => $catGeneral->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS, 'requires_subject_results' => true, 'sort_order' => 10],
            ['zqf_level_code' => 'L2A', 'level_label' => 'Level 2(A)', 'name' => 'Junior Secondary Education Certificate (Grade 9)', 'billing_category_id' => $catGeneral->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS, 'requires_subject_results' => true, 'sort_order' => 20],
            ['zqf_level_code' => 'L2B', 'level_label' => 'Level 2(B)', 'name' => 'Senior Secondary Education Certificate (Grade 12)', 'billing_category_id' => $catGeneral->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS, 'requires_subject_results' => true, 'sort_order' => 30],

            // Local Certificates & Diplomas
            ['zqf_level_code' => 'L3', 'level_label' => 'Level 3', 'name' => 'Certificate (e.g. Taxation)', 'billing_category_id' => $catCerts->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT, 'requires_subject_results' => false, 'sort_order' => 40],
            ['zqf_level_code' => 'L4', 'level_label' => 'Level 4', 'name' => 'Certificate (e.g. CA Certificate)', 'billing_category_id' => $catCerts->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT, 'requires_subject_results' => false, 'sort_order' => 50],
            ['zqf_level_code' => 'L5', 'level_label' => 'Level 5', 'name' => 'Higher Certificate / Technical Certificate', 'billing_category_id' => $catCerts->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT, 'requires_subject_results' => false, 'sort_order' => 60],
            ['zqf_level_code' => 'L6', 'level_label' => 'Level 6', 'name' => 'Diploma (Advanced Diplomas, Technical Diplomas)', 'billing_category_id' => $catCerts->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT, 'requires_subject_results' => false, 'sort_order' => 70],

            // Local Degrees
            ['zqf_level_code' => 'L7', 'level_label' => 'Level 7', 'name' => "Bachelor’s Degree (Ordinary or Honours)", 'billing_category_id' => $catDegrees->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT, 'requires_subject_results' => false, 'sort_order' => 80],
            ['zqf_level_code' => 'L8', 'level_label' => 'Level 8', 'name' => 'Postgraduate Diploma / Postgraduate Certificate', 'billing_category_id' => $catDegrees->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT, 'requires_subject_results' => false, 'sort_order' => 90],
            ['zqf_level_code' => 'L9', 'level_label' => 'Level 9', 'name' => 'Master’s Degree', 'billing_category_id' => $catDegrees->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT, 'requires_subject_results' => false, 'sort_order' => 100],
            ['zqf_level_code' => 'L10', 'level_label' => 'Level 10', 'name' => 'Doctoral Degree', 'billing_category_id' => $catDegrees->id, 'certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT, 'requires_subject_results' => false, 'sort_order' => 110],
        ];

        foreach ($rows as $row) {
            QualificationType::query()->updateOrCreate(
                ['zqf_level_code' => $row['zqf_level_code'], 'name' => $row['name']],
                array_merge($row, ['is_active' => true]),
            );
        }
    }
}
