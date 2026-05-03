<?php

namespace App\Support\Reports;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ReportDateRange
{
    /**
     * @return array{from: Carbon, to: Carbon, range: string}
     */
    public static function fromRequest(Request $request, string $defaultRange = 'last30'): array
    {
        $range = (string) $request->query('range', $defaultRange);

        $to = $request->query('to') ? Carbon::parse((string) $request->query('to'))->endOfDay() : now()->endOfDay();
        $from = match ($range) {
            'ytd' => $to->copy()->startOfYear(),
            'last7' => $to->copy()->subDays(7)->startOfDay(),
            'last90' => $to->copy()->subDays(90)->startOfDay(),
            'custom' => ($request->query('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : $to->copy()->subDays(30)->startOfDay()),
            default => $to->copy()->subDays(30)->startOfDay(),
        };

        return [
            'from' => $from,
            'to' => $to,
            'range' => $range,
        ];
    }
}
