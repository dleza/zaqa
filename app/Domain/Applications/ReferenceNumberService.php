<?php

namespace App\Domain\Applications;

use App\Models\Application;
use App\Models\Qualification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReferenceNumberService
{
    private const APPLICATION_SEQUENCE_TYPE = 'application';

    private const MAX_QUALIFICATIONS_PER_APPLICATION = 99;

    public function generateApplicationNumber(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($year) {
            $row = DB::table('reference_sequences')
                ->where('type', self::APPLICATION_SEQUENCE_TYPE)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('reference_sequences')->insert([
                    'type' => self::APPLICATION_SEQUENCE_TYPE,
                    'year' => $year,
                    'last_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $next = 1;
            } else {
                $next = (int) $row->last_number + 1;
                DB::table('reference_sequences')
                    ->where('id', $row->id)
                    ->update([
                        'last_number' => $next,
                        'updated_at' => now(),
                    ]);
            }

            return $this->formatApplicationNumber($year, $next);
        });
    }

    public function assignQualificationVerificationReferences(Application $application): void
    {
        $application->loadMissing('qualifications');

        foreach ($application->qualifications->sortBy('id')->values() as $qualification) {
            /** @var Qualification $qualification */
            $existing = trim((string) ($qualification->verification_reference_number ?? ''));
            if ($existing !== '') {
                continue;
            }

            $qualification->verification_reference_number = $this->generateQualificationVerificationReference($application);
            try {
                $qualification->save();
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    $qualification->verification_reference_number = $this->generateQualificationVerificationReference($application);
                    $qualification->save();
                } else {
                    throw $e;
                }
            }
        }
    }

    public function generateQualificationVerificationReference(Application $application): string
    {
        $application->loadMissing('qualifications');
        $applicationRef = trim((string) $application->application_number);

        if ($this->isNumericApplicationReference($applicationRef)) {
            return $this->generateNumericQualificationReference($application, $applicationRef);
        }

        return $this->generateLegacyQualificationReference();
    }

    public function isNumericApplicationReference(string $reference): bool
    {
        return preg_match('/^\d{4}-\d{6}$/', trim($reference)) === 1;
    }

    public function isNumericQualificationReference(string $reference): bool
    {
        return preg_match('/^\d{4}-\d{6}-\d{2}$/', trim($reference)) === 1;
    }

    private function generateNumericQualificationReference(Application $application, string $applicationRef): string
    {
        return DB::transaction(function () use ($application, $applicationRef) {
            Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            $maxSequence = Qualification::query()
                ->where('application_id', $application->id)
                ->whereNotNull('verification_reference_number')
                ->pluck('verification_reference_number')
                ->map(fn ($reference) => $this->numericQualificationSequenceForApplication($applicationRef, (string) $reference))
                ->filter(fn (?int $sequence) => $sequence !== null)
                ->max() ?? 0;

            $nextSequence = $maxSequence + 1;
            if ($nextSequence > self::MAX_QUALIFICATIONS_PER_APPLICATION) {
                throw new RuntimeException('Maximum qualifications per application exceeded.');
            }

            return sprintf('%s-%02d', $applicationRef, $nextSequence);
        });
    }

    private function numericQualificationSequenceForApplication(string $applicationRef, string $reference): ?int
    {
        $prefix = $applicationRef.'-';
        if (! str_starts_with($reference, $prefix)) {
            return null;
        }

        $suffix = substr($reference, strlen($prefix));
        if (preg_match('/^\d{2}$/', $suffix) !== 1) {
            return null;
        }

        return (int) $suffix;
    }

    private function generateLegacyQualificationReference(): string
    {
        $attempts = 0;

        while ($attempts < 12) {
            $attempts++;
            $candidate = 'ZAQA-Q-'.now()->format('Y').'-'.strtoupper(Str::random(10));

            if (! Qualification::query()->where('verification_reference_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique qualification verification reference number.');
    }

    private function formatApplicationNumber(int $year, int $sequence): string
    {
        return sprintf('%d-%06d', $year, $sequence);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23000' && in_array((int) $driverCode, [1062, 19], true);
    }
}
