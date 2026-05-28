<?php

namespace Database\Seeders;

use App\Models\AwardingInstitution;
use App\Models\Country;
use Illuminate\Database\Seeder;

class AwardingInstitutionsSeeder extends Seeder
{
    public function run(): void
    {
        $zambia = Country::query()->where('iso_code', 'ZMB')->first();
        $southAfrica = Country::query()->where('iso_code', 'ZAF')->first();
        $zimbabwe = Country::query()->where('iso_code', 'ZWE')->first();
        $tanzania = Country::query()->where('iso_code', 'TZA')->first();
        $kenya = Country::query()->where('iso_code', 'KEN')->first();
        $uganda = Country::query()->where('iso_code', 'UGA')->first();
        $uk = Country::query()->where('iso_code', 'GBR')->first();
        $usa = Country::query()->where('iso_code', 'USA')->first();
        $india = Country::query()->where('iso_code', 'IND')->first();

        if ($zambia) {
            $rows = [
                ['country_id' => $zambia->id, 'name' => 'University of Zambia', 'sort_order' => 1],
                ['country_id' => $zambia->id, 'name' => 'Copperbelt University', 'sort_order' => 2],
                ['country_id' => $zambia->id, 'name' => 'Mulungushi University', 'sort_order' => 3],
                ['country_id' => $zambia->id, 'name' => 'Zambia Open University', 'sort_order' => 4],
                ['country_id' => $zambia->id, 'name' => 'Examinations Council of Zambia (ECZ)', 'sort_order' => 10],
                ['country_id' => $zambia->id, 'name' => 'Technical Education, Vocational and Entrepreneurship Training Authority (TEVTA)', 'sort_order' => 11],
                ['country_id' => $zambia->id, 'name' => 'Zambia Institute of Chartered Accountants', 'sort_order' => 20],
                ['country_id' => $zambia->id, 'name' => 'Evelyn Hone College', 'sort_order' => 30],
                ['country_id' => $zambia->id, 'name' => 'National Institute of Public Administration', 'sort_order' => 31],
            ];
            foreach ($rows as $row) {
                AwardingInstitution::updateOrCreate(
                    ['country_id' => $row['country_id'], 'name' => $row['name']],
                    ['is_active' => true, 'sort_order' => $row['sort_order']],
                );
            }
        }

        if ($southAfrica) {
            $rows = [
                ['country_id' => $southAfrica->id, 'name' => 'University of Cape Town', 'sort_order' => 1],
                ['country_id' => $southAfrica->id, 'name' => 'University of the Witwatersrand', 'sort_order' => 2],
                ['country_id' => $southAfrica->id, 'name' => 'University of Pretoria', 'sort_order' => 3],
                ['country_id' => $southAfrica->id, 'name' => 'Stellenbosch University', 'sort_order' => 4],
            ];
            foreach ($rows as $row) {
                AwardingInstitution::updateOrCreate(
                    ['country_id' => $row['country_id'], 'name' => $row['name']],
                    ['is_active' => true, 'sort_order' => $row['sort_order']],
                );
            }
        }

        $regional = [
            $zimbabwe?->id => [
                'University of Zimbabwe',
                'National University of Science and Technology (Zimbabwe)',
            ],
            $tanzania?->id => [
                'University of Dar es Salaam',
                'Sokoine University of Agriculture',
            ],
            $kenya?->id => [
                'University of Nairobi',
                'Kenyatta University',
            ],
            $uganda?->id => [
                'Makerere University',
                'Mbarara University of Science and Technology',
            ],
            $uk?->id => [
                'University of Oxford',
                'University of Cambridge',
                'University of London',
            ],
            $usa?->id => [
                'Harvard University',
                'Massachusetts Institute of Technology',
                'Stanford University',
            ],
            $india?->id => [
                'University of Delhi',
                'Indian Institute of Technology Delhi',
                'University of Mumbai',
            ],
        ];

        foreach ($regional as $countryId => $names) {
            if (! $countryId) {
                continue;
            }
            foreach (array_values($names) as $idx => $name) {
                AwardingInstitution::updateOrCreate(
                    ['country_id' => $countryId, 'name' => $name],
                    ['is_active' => true, 'sort_order' => 100 + $idx],
                );
            }
        }
    }
}

