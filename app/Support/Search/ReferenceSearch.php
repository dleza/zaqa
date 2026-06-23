<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class ReferenceSearch
{
    public const MIN_PREFIX_LENGTH = 3;

    public static function normalize(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    public static function isUsablePrefix(?string $normalized): bool
    {
        return $normalized !== null && mb_strlen($normalized) >= self::MIN_PREFIX_LENGTH;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public static function applyApplicationReference(Builder $query, ?string $raw, string $column = 'application_number'): void
    {
        $term = self::normalize($raw);
        if (! self::isUsablePrefix($term)) {
            return;
        }

        $query->where($column, 'like', $term.'%');
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public static function applyQualificationReference(Builder $query, ?string $raw, string $column = 'verification_reference_number'): void
    {
        $term = self::normalize($raw);
        if (! self::isUsablePrefix($term)) {
            return;
        }

        $query->where($column, 'like', $term.'%');
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public static function applyCertificateReference(Builder $query, ?string $raw, string $column = 'certificate_number'): void
    {
        $term = self::normalize($raw);
        if (! self::isUsablePrefix($term)) {
            return;
        }

        $query->where($column, 'like', $term.'%');
    }

    /**
     * @param  Builder<\App\Models\Qualification>  $query
     */
    public static function applyToQualificationQuery(Builder $query, ?string $applicationReference, ?string $qualificationReference): void
    {
        $appRef = self::normalize($applicationReference);
        if (self::isUsablePrefix($appRef)) {
            $query->whereHas('application', fn (Builder $aq) => self::applyApplicationReference($aq, $appRef));
        }

        self::applyQualificationReference($query, $qualificationReference);
    }

    /**
     * @param  Builder<\App\Models\Application>  $query
     */
    public static function applyToApplicationQuery(Builder $query, ?string $applicationReference, ?string $qualificationReference): void
    {
        $appRef = self::normalize($applicationReference);
        $qualRef = self::normalize($qualificationReference);

        if (self::isUsablePrefix($appRef)) {
            self::applyApplicationReference($query, $appRef);
        }

        if (self::isUsablePrefix($qualRef)) {
            $query->where(function (Builder $inner) use ($qualRef) {
                $inner->whereHas('qualifications', fn (Builder $qq) => self::applyQualificationReference($qq, $qualRef))
                    ->orWhereHas('qualification', fn (Builder $qq) => self::applyQualificationReference($qq, $qualRef));
            });
        }
    }

    /**
     * @return array{application_reference: string, qualification_reference: string}
     */
    public static function filterPayloadFromRequest(Request $request): array
    {
        return [
            'application_reference' => (string) $request->query('application_reference', ''),
            'qualification_reference' => (string) $request->query('qualification_reference', ''),
        ];
    }
}
