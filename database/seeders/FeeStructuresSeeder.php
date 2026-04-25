<?php

namespace Database\Seeders;

use App\Models\BillingCategory;
use App\Models\FeeStructure;
use Illuminate\Database\Seeder;

class FeeStructuresSeeder extends Seeder
{
    public function run(): void
    {
        $effectiveFrom = now()->startOfDay();

        $map = [
            'LOCAL_GENERAL_EDU' => ['local' => 5000, 'foreign' => 120000], // 50.00 / 1200.00
            'LOCAL_CERTS_DIPLOMAS' => ['local' => 20000, 'foreign' => 120000], // 200.00 / 1200.00
            'LOCAL_DEGREES' => ['local' => 50000, 'foreign' => 120000], // 500.00 / 1200.00
            'FOREIGN_QUALIFICATIONS' => ['local' => null, 'foreign' => 120000], // foreign-only category
        ];

        foreach ($map as $code => $fees) {
            $category = BillingCategory::query()->where('code', $code)->firstOrFail();

            FeeStructure::query()->updateOrCreate(
                [
                    'billing_category_id' => $category->id,
                    'effective_from' => $effectiveFrom,
                ],
                [
                    'local_fee_cents' => $fees['local'],
                    'foreign_fee_cents' => $fees['foreign'],
                    'currency' => 'ZMW',
                    'effective_to' => null,
                    'is_active' => true,
                    'approved_by_user_id' => null,
                    'change_reason' => 'Initial ZAQA fee structure seed.',
                ],
            );
        }
    }
}

