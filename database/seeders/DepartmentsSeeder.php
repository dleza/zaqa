<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'Finance Department', 'code' => 'FIN', 'sort_order' => 10],
            ['name' => 'IT Department', 'code' => 'IT', 'sort_order' => 20],
            ['name' => 'Human Resource', 'code' => 'HR', 'sort_order' => 30],
            ['name' => 'Other', 'code' => 'OTHER', 'sort_order' => 99],
        ];

        foreach ($items as $item) {
            $dept = Department::query()->firstOrCreate(['name' => $item['name']]);
            $dept->forceFill([
                'code' => $dept->code ?: $item['code'],
                'sort_order' => (int) ($dept->sort_order ?? 0) ?: (int) $item['sort_order'],
                'is_active' => true,
            ])->save();
        }
    }
}

