<?php

namespace App\Domain\Applications;

use App\Models\Application;

class ApplicationNotificationContact
{
    public const MODE_APPLICANT_ACCOUNT = 'applicant_account';

    public const MODE_ADDITIONAL_EMAIL = 'additional_email';

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{
     *   mode: string,
     *   additional_email: ?string,
     *   additional_name: ?string,
     *   additional_relationship: ?string
     * }
     */
    public static function fromMetadata(?array $metadata): array
    {
        $meta = $metadata ?? [];
        $mode = (string) ($meta['notification_contact_mode'] ?? self::MODE_APPLICANT_ACCOUNT);
        if (! in_array($mode, [self::MODE_APPLICANT_ACCOUNT, self::MODE_ADDITIONAL_EMAIL], true)) {
            $mode = self::MODE_APPLICANT_ACCOUNT;
        }

        $additionalEmail = trim((string) ($meta['additional_notification_email'] ?? ''));

        return [
            'mode' => $mode,
            'additional_email' => $additionalEmail !== '' ? $additionalEmail : null,
            'additional_name' => self::nullableTrim($meta['additional_notification_name'] ?? null),
            'additional_relationship' => self::nullableTrim($meta['additional_notification_relationship'] ?? null),
        ];
    }

    public static function additionalEmailForOutcome(Application $application): ?string
    {
        $contact = self::fromMetadata((array) ($application->metadata ?? []));
        if ($contact['mode'] !== self::MODE_ADDITIONAL_EMAIL) {
            return null;
        }

        $email = $contact['additional_email'];
        if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $application->loadMissing('applicant');
        $applicantEmail = strtolower(trim((string) ($application->applicant?->email ?? '')));
        if ($applicantEmail !== '' && strtolower($email) === $applicantEmail) {
            return null;
        }

        return $email;
    }

    public static function adminLabel(Application $application): string
    {
        $contact = self::fromMetadata((array) ($application->metadata ?? []));
        if ($contact['mode'] === self::MODE_ADDITIONAL_EMAIL && $contact['additional_email']) {
            return 'Applicant account + '.$contact['additional_email'];
        }

        return 'Applicant account';
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mergeIntoMetadata(array $metadata, array $data, string $submittingFor): array
    {
        if ($submittingFor !== 'other') {
            unset(
                $metadata['notification_contact_mode'],
                $metadata['additional_notification_email'],
                $metadata['additional_notification_name'],
                $metadata['additional_notification_relationship'],
            );

            return $metadata;
        }

        $mode = (string) ($data['notification_contact_mode'] ?? self::MODE_APPLICANT_ACCOUNT);
        if (! in_array($mode, [self::MODE_APPLICANT_ACCOUNT, self::MODE_ADDITIONAL_EMAIL], true)) {
            $mode = self::MODE_APPLICANT_ACCOUNT;
        }

        $metadata['notification_contact_mode'] = $mode;

        if ($mode === self::MODE_ADDITIONAL_EMAIL) {
            $metadata['additional_notification_email'] = trim((string) ($data['additional_notification_email'] ?? ''));
            $name = self::nullableTrim($data['additional_notification_name'] ?? null);
            $relationship = self::nullableTrim($data['additional_notification_relationship'] ?? null);
            if ($name !== null) {
                $metadata['additional_notification_name'] = $name;
            } else {
                unset($metadata['additional_notification_name']);
            }
            if ($relationship !== null) {
                $metadata['additional_notification_relationship'] = $relationship;
            } else {
                unset($metadata['additional_notification_relationship']);
            }
        } else {
            unset(
                $metadata['additional_notification_email'],
                $metadata['additional_notification_name'],
                $metadata['additional_notification_relationship'],
            );
        }

        return $metadata;
    }

    private static function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }
}
