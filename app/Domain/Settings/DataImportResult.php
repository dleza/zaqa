<?php

namespace App\Domain\Settings;

final class DataImportResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $errors = [];

    public function summaryLine(): string
    {
        if ($this->errors !== [] && $this->created === 0 && $this->updated === 0 && $this->skipped === 0) {
            $first = $this->errors[0] ?? '';
            if (str_contains($first, 'Missing required column') || str_contains($first, 'empty')) {
                return count($this->errors).' import issue(s) — see details below.';
            }
        }

        $parts = [];
        if ($this->created > 0) {
            $parts[] = $this->created.' created';
        }
        if ($this->updated > 0) {
            $parts[] = $this->updated.' updated';
        }
        if ($this->skipped > 0) {
            $parts[] = $this->skipped.' skipped';
        }
        $base = $parts !== [] ? implode(', ', $parts) : 'No rows processed';

        if ($this->errors !== []) {
            return $base.'. '.count($this->errors).' row message(s) — see details below.';
        }

        return $base.'.';
    }
}
