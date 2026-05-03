<?php

namespace Database\Seeders;

use App\Models\CertificateSubject;
use Illuminate\Database\Seeder;

class CertificateSubjectsSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            // Core
            'English Language',
            'Mathematics',
            'Additional Mathematics',
            'Civic Education',

            // Sciences
            'Biology',
            'Chemistry',
            'Physics',
            'Science',

            // Business
            'Commerce',
            'Principles of Accounts',
            'Entrepreneurship',

            // Humanities
            'Geography',
            'History',
            'Religious Education',

            // Technical
            'Computer Studies',
            'Computer Science',
            'Design and Technology',
            'Metalwork',
            'Woodwork',
            'Geometrical & Mechanical Drawing',

            // Creative
            'Art and Design',
            'Fashion and Fabrics',
            'Food and Nutrition',
            'Musical Education',

            // Languages
            'French',
            'Cinyanja',
            'Icibemba',
            'Chitonga',
            'Silozi',
            'Lunda',
            'Luvale',
            'Kiikaonde',

            // Others
            'Agricultural Science',
            'Physical Education',
        ];

        foreach (array_values($names) as $i => $name) {
            CertificateSubject::query()->updateOrCreate(
                ['name' => $name],
                ['sort_order' => ($i + 1) * 10, 'is_active' => true],
            );
        }
    }
}
