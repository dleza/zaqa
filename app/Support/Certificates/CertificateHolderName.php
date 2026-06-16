<?php

namespace App\Support\Certificates;

use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;

class CertificateHolderName
{
    public const SOURCE_NAMES_AS_ON_DOCUMENT = 'names_as_on_qualification_document';

    public const SOURCE_QUALIFICATION_HOLDER = 'qualification_holder_name';

    public const SOURCE_APPLICATION_METADATA = 'application_metadata';

    public const SOURCE_APPLICANT = 'applicant';

    /**
     * @return array{raw: string|null, display: string, source: string|null}
     */
    public static function resolve(Qualification $qualification, Application $application): array
    {
        [$raw, $source] = self::resolveRawWithSource($qualification, $application);
        $formatted = self::format($raw);

        return [
            'raw' => $raw,
            'display' => ($formatted !== null && $formatted !== '') ? $formatted : '—',
            'source' => $source,
        ];
    }

    /**
     * @return array{raw: string|null, display: string, source: string|null}
     */
    public static function metadataSnapshot(array $holder): array
    {
        return [
            'holder_name_raw' => $holder['raw'],
            'holder_name_display' => $holder['display'],
            'holder_name_source' => $holder['source'],
        ];
    }

    public static function displayFromCertificateMetadata(?array $metadata): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }

        $display = trim((string) ($metadata['holder_name_display'] ?? ''));

        return $display !== '' ? $display : null;
    }

    public static function displayForPublicVerification(
        QualificationCertificate $certificate,
        ?Qualification $qualification,
        ?Application $application,
    ): ?string {
        $fromMetadata = self::displayFromCertificateMetadata($certificate->metadata);
        if ($fromMetadata !== null) {
            return $fromMetadata;
        }

        if ($qualification instanceof Qualification && $application instanceof Application) {
            return self::resolve($qualification, $application)['display'];
        }

        $legacy = trim((string) ($qualification?->qualification_holder_name ?? ''));

        return $legacy !== '' ? $legacy : null;
    }

    public static function format(?string $name): ?string
    {
        $name = self::normalizeWhitespace($name ?? '');
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $name) ?: [];

        return implode(' ', array_map(fn (string $part) => self::formatToken($part), $parts));
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function resolveRawWithSource(Qualification $qualification, Application $application): array
    {
        $namesAsOnDocument = self::normalizeWhitespace((string) ($qualification->names_as_on_qualification_document ?? ''));
        if ($namesAsOnDocument !== '') {
            return [$namesAsOnDocument, self::SOURCE_NAMES_AS_ON_DOCUMENT];
        }

        $holderName = self::normalizeWhitespace((string) ($qualification->qualification_holder_name ?? ''));
        if ($holderName !== '') {
            return [$holderName, self::SOURCE_QUALIFICATION_HOLDER];
        }

        $meta = $application->metadata;
        if (is_array($meta) && isset($meta['verification_subject']['full_name'])) {
            $fromMeta = self::normalizeWhitespace((string) $meta['verification_subject']['full_name']);
            if ($fromMeta !== '') {
                return [$fromMeta, self::SOURCE_APPLICATION_METADATA];
            }
        }

        $application->loadMissing('applicant');
        $applicantName = self::normalizeWhitespace((string) ($application->applicant?->name ?? ''));
        if ($applicantName !== '') {
            return [$applicantName, self::SOURCE_APPLICANT];
        }

        return [null, null];
    }

    private static function formatToken(string $token): string
    {
        if ($token === '') {
            return '';
        }

        if (str_contains($token, '-')) {
            return implode('-', array_map(
                fn (string $segment) => self::titleCaseSegment($segment),
                explode('-', $token),
            ));
        }

        if (str_contains($token, "'")) {
            $segments = explode("'", $token);
            $formatted = [];
            foreach ($segments as $index => $segment) {
                $formatted[] = $index === 0
                    ? self::titleCaseSegment($segment)
                    : self::titleCaseSegment($segment);
            }

            return implode("'", $formatted);
        }

        return self::titleCaseSegment($token);
    }

    private static function titleCaseSegment(string $segment): string
    {
        $segment = mb_strtolower($segment, 'UTF-8');
        if ($segment === '') {
            return '';
        }

        return mb_convert_case($segment, MB_CASE_TITLE, 'UTF-8');
    }

    private static function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
