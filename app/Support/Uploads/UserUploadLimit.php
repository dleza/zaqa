<?php

namespace App\Support\Uploads;

final class UserUploadLimit
{
    public static function maxFileSizeMb(): int
    {
        $mb = (int) config('zaqa.uploads.max_file_size_mb', 3);

        return max(1, $mb);
    }

    public static function maxFileSizeKb(): int
    {
        return self::maxFileSizeMb() * 1024;
    }

    public static function maxFileSizeLabel(): string
    {
        return 'Maximum file size: '.self::maxFileSizeMb().' MB';
    }

    public static function pdfOrImageHint(): string
    {
        return 'PDF or image files only (JPG, PNG, WEBP) — max '.self::maxFileSizeMb().' MB';
    }

    public static function fileTooLargeMessage(): string
    {
        return 'The uploaded file is too large. Maximum allowed size is '.self::maxFileSizeMb().' MB.';
    }

    public static function fileFailedToUploadMessage(): string
    {
        return self::fileTooLargeMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public static function inertiaProps(): array
    {
        return [
            'max_file_size_mb' => self::maxFileSizeMb(),
            'max_file_size_label' => self::maxFileSizeLabel(),
            'pdf_or_image_hint' => self::pdfOrImageHint(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function validationMessages(): array
    {
        $message = self::fileTooLargeMessage();

        return [
            'file.max' => $message,
            'file.uploaded' => self::fileFailedToUploadMessage(),
            'identity_file.max' => $message,
            'identity_file.uploaded' => self::fileFailedToUploadMessage(),
            'evaluation_report.max' => $message,
            'evaluation_report.uploaded' => self::fileFailedToUploadMessage(),
            'attachment.max' => $message,
            'attachment.uploaded' => self::fileFailedToUploadMessage(),
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    public static function validationMessagesFor(array $fields): array
    {
        $message = self::fileTooLargeMessage();
        $failed = self::fileFailedToUploadMessage();
        $out = [];

        foreach ($fields as $field) {
            $out[$field.'.max'] = $message;
            $out[$field.'.uploaded'] = $failed;
        }

        return $out;
    }
}
