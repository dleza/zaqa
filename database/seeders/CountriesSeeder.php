<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CountriesSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seed-data/countries-iso3.json');
        $raw = File::get($path);
        $rows = json_decode($raw, true);

        if (! is_array($rows)) {
            throw new \RuntimeException('Invalid countries seed file: '.$path);
        }

        $priority = [
            'ZMB' => 1,
            'ZAF' => 2,
            'ZWE' => 3,
            'NAM' => 4,
            'BWA' => 5,
            'MWI' => 6,
            'TZA' => 7,
            'KEN' => 8,
            'UGA' => 9,
            'GBR' => 20,
            'USA' => 21,
            'IND' => 22,
        ];

        foreach ($rows as $row) {
            $iso3 = strtoupper((string) ($row['iso3'] ?? ''));
            $name = (string) ($row['name'] ?? '');
            if ($iso3 === '' || $name === '') {
                continue;
            }

            $payload = [
                'iso_code' => $iso3,
                'name' => $name,
                'is_active' => true,
                'sort_order' => $priority[$iso3] ?? 1000,
            ];

            Country::updateOrCreate(['iso_code' => $iso3], $payload);
        }
    }
}

