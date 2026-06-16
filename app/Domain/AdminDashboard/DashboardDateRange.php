<?php

namespace App\Domain\AdminDashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Dashboard reporting window (7 or 30 days). Custom ranges belong under Reports.
 */
final class DashboardDateRange
{
    public function __construct(
        public readonly int $selected,
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $selected = (int) $request->query('range', 30);
        if (! in_array($selected, [7, 30], true)) {
            $selected = 30;
        }

        $now = Carbon::now();

        return new self(
            selected: $selected,
            from: $now->copy()->subDays($selected - 1)->startOfDay(),
            to: $now->copy()->endOfDay(),
        );
    }

    /**
     * @return array{
     *     selected: int,
     *     from: string,
     *     to: string,
     *     label: string,
     *     options: list<array{label: string, value: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'selected' => $this->selected,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $this->label(),
            'options' => [
                ['label' => 'Last 30 days', 'value' => 30],
                ['label' => 'Last 7 days', 'value' => 7],
            ],
        ];
    }

    public function label(): string
    {
        return 'Last '.$this->selected.' days';
    }
}
