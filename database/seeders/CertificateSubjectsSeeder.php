<?php

namespace Database\Seeders;

use App\Models\CertificateSubject;
use Illuminate\Database\Seeder;

class CertificateSubjectsSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'English Language',
            'Mathematics',
            'Science',
            'Social Studies',
            'Religious Education',
            'Zambian Languages',
            'History',
            'Geography',
            'Civic Education',
            'Computer Studies',
            'Art',
            'Physical Education',
            'Design and Technology',
            'Business Studies',
            'Agricultural Science',
        ];

        foreach (array_values($names) as $i => $name) {
            CertificateSubject::query()->updateOrCreate(
                ['name' => $name],
                ['sort_order' => ($i + 1) * 10, 'is_active' => true],
            );
        }
    }
}
