<?php

namespace App\Domain\Settings;

final class AccreditationStatementExcelImportSummary
{
    public int $updated = 0;

    public int $skipped_empty = 0;

    public int $skipped_existing = 0;

    public int $not_found = 0;

    public int $invalid_rows = 0;

    public int $total_processed = 0;

    /** @var list<string> */
    public array $errors = [];

    public function summaryLine(): string
    {
        if ($this->errors !== [] && $this->updated === 0 && $this->total_processed === 0) {
            return count($this->errors).' import issue(s) — see details below.';
        }

        $parts = [];
        if ($this->updated > 0) {
            $parts[] = $this->updated.' updated';
        }
        if ($this->skipped_empty > 0) {
            $parts[] = $this->skipped_empty.' skipped (empty statement)';
        }
        if ($this->skipped_existing > 0) {
            $parts[] = $this->skipped_existing.' skipped (existing statement)';
        }
        if ($this->not_found > 0) {
            $parts[] = $this->not_found.' not found';
        }
        if ($this->invalid_rows > 0) {
            $parts[] = $this->invalid_rows.' invalid';
        }

        $base = $parts !== [] ? implode(', ', $parts) : 'No rows updated';
        $base .= " ({$this->total_processed} row(s) processed)";

        if ($this->errors !== []) {
            $base .= '. '.count($this->errors).' message(s) — see details below.';
        }

        return $base.'.';
    }

    /**
     * @return array<string, int|list<string>>
     */
    public function toArray(): array
    {
        return [
            'updated' => $this->updated,
            'skipped_empty' => $this->skipped_empty,
            'skipped_existing' => $this->skipped_existing,
            'not_found' => $this->not_found,
            'invalid_rows' => $this->invalid_rows,
            'total_processed' => $this->total_processed,
            'errors' => $this->errors,
        ];
    }
}
