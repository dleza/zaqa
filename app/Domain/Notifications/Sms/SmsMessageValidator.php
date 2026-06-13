<?php

namespace App\Domain\Notifications\Sms;

final class SmsMessageValidator
{
    public function maxLength(): int
    {
        return max(1, (int) config('sms.max_length', 159));
    }

    public function isValidLength(string $message): bool
    {
        return mb_strlen($message) <= $this->maxLength();
    }

    public function assertValidLength(string $message): void
    {
        if (! $this->isValidLength($message)) {
            throw new \InvalidArgumentException(sprintf(
                'SMS message exceeds maximum length of %d characters (got %d).',
                $this->maxLength(),
                mb_strlen($message),
            ));
        }
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    public function renderTemplate(string $templateKey, array $placeholders = []): string
    {
        $template = config('sms_templates.'.$templateKey);

        if (! is_string($template) || trim($template) === '') {
            throw new \InvalidArgumentException("Unknown SMS template [{$templateKey}].");
        }

        $search = [];
        $replace = [];
        foreach ($placeholders as $key => $value) {
            $search[] = ':'.ltrim((string) $key, ':');
            $replace[] = (string) $value;
        }

        $message = str_replace($search, $replace, $template);
        $this->assertValidLength($message);

        return $message;
    }
}
