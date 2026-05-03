<?php

namespace App\Support\Reports;

use Illuminate\Support\Facades\DB;

final class SqlDialect
{
    public static function monthBucket(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "date_format({$column}, '%Y-%m')",
        };
    }

    public static function dateBucket(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date({$column})",
            default => "date({$column})",
        };
    }
}
