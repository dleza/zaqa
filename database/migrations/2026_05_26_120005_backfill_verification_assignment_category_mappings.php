<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        self::backfill();
    }

    public function down(): void
    {
        // No-op: do not remove mappings on rollback.
    }

    public static function backfill(): void
    {
        $now = now();

        DB::table('verification_assignment_categories')
            ->select(['id', 'type', 'country_id', 'awarding_institution_id'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($now) {
                $countryMappings = [];
                $institutionMappings = [];

                foreach ($rows as $r) {
                    $categoryId = (int) $r->id;
                    $type = (string) $r->type;

                    if ($type === 'foreign_country' && $r->country_id !== null) {
                        $countryMappings[] = [
                            'verification_assignment_category_id' => $categoryId,
                            'country_id' => (int) $r->country_id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($type === 'local_institution' && $r->awarding_institution_id !== null) {
                        $institutionMappings[] = [
                            'verification_assignment_category_id' => $categoryId,
                            'awarding_institution_id' => (int) $r->awarding_institution_id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($countryMappings !== []) {
                    DB::table('verification_assignment_category_countries')->insertOrIgnore($countryMappings);
                }
                if ($institutionMappings !== []) {
                    DB::table('verification_assignment_category_awarding_institutions')->insertOrIgnore($institutionMappings);
                }
            });
    }
};

