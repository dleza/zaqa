<?php

namespace App\Domain\Notifications\Sms;

use App\Models\SmsLog;

final class SmsLogAdminPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function presentSummary(SmsLog $log): array
    {
        return [
            'id' => $log->id,
            'status' => $log->status,
            'skip_reason' => $log->skip_reason,
            'message_type' => $log->message_type,
            'phone_number' => $this->maskPhone((string) $log->phone_number),
            'provider' => $log->provider,
            'http_status' => $log->http_status,
            'message_length' => $log->message_length,
            'application' => $log->application ? [
                'id' => $log->application->id,
                'application_number' => $log->application->application_number,
            ] : null,
            'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
            'created_at' => optional($log->created_at)->toIso8601String(),
            'sent_at' => optional($log->sent_at)->toIso8601String(),
            'show_url' => route('admin.settings.sms.logs.show', ['smsLog' => $log->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDetail(SmsLog $log): array
    {
        $messageBody = $this->messageBodyForAdmin((string) $log->message_type, $log->message_body);

        return [
            'id' => $log->id,
            'status' => $log->status,
            'skip_reason' => $log->skip_reason,
            'message_type' => $log->message_type,
            'phone_number' => $this->maskPhone((string) $log->phone_number),
            'normalized_phone' => $this->maskPhone($log->normalized_phone),
            'message_body' => $messageBody,
            'message_body_redacted' => $this->isMessageBodyRedacted((string) $log->message_type),
            'message_length' => $log->message_length,
            'provider' => $log->provider,
            'provider_reference' => $log->provider_reference,
            'http_status' => $log->http_status,
            'provider_response' => $log->provider_response,
            'attempt_count' => $log->attempt_count,
            'application' => $log->application ? [
                'id' => $log->application->id,
                'application_number' => $log->application->application_number,
            ] : null,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
            'balance_adjustment' => $log->balanceAdjustment ? [
                'id' => $log->balanceAdjustment->id,
                'balance_before' => $log->balanceAdjustment->balance_before,
                'balance_after' => $log->balanceAdjustment->balance_after,
            ] : null,
            'created_at' => optional($log->created_at)->toIso8601String(),
            'sent_at' => optional($log->sent_at)->toIso8601String(),
        ];
    }

    public function maskPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;
        if (strlen($digits) <= 4) {
            return $phone;
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }

    public function messageBodyForAdmin(string $messageType, ?string $messageBody): ?string
    {
        if ($messageBody === null || $messageBody === '') {
            return $messageBody;
        }

        $sensitivePlaceholders = $this->sensitivePlaceholders($messageType);
        if ($sensitivePlaceholders === []) {
            return $messageBody;
        }

        $template = config('sms_templates.'.$messageType);
        if (! is_string($template) || trim($template) === '') {
            return $messageBody;
        }

        $redacted = $messageBody;
        foreach ($sensitivePlaceholders as $placeholder) {
            $redacted = $this->redactPlaceholder($template, $redacted, (string) $placeholder);
        }

        return $redacted;
    }

    public function isMessageBodyRedacted(string $messageType): bool
    {
        return $this->sensitivePlaceholders($messageType) !== [];
    }

    /**
     * @return list<string>
     */
    private function sensitivePlaceholders(string $messageType): array
    {
        $configured = config('sms.admin_redaction.'.$messageType, []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $placeholder): string => trim((string) $placeholder),
            $configured,
        ), static fn (string $placeholder): bool => $placeholder !== ''));
    }

    private function redactPlaceholder(string $template, string $message, string $placeholderName): string
    {
        $token = ':'.ltrim($placeholderName, ':');
        $tokenPos = strpos($template, $token);
        if ($tokenPos === false) {
            return $message;
        }

        $prefix = substr($template, 0, $tokenPos);
        $afterToken = substr($template, $tokenPos + strlen($token));
        $suffix = '';

        if (preg_match('/^([^:]*)/', $afterToken, $matches) === 1) {
            $suffix = $matches[1];
        }

        $pattern = '/'.preg_quote($prefix, '/').'\K';
        $pattern .= $suffix !== ''
            ? '.+?(?='.preg_quote($suffix, '/').')'
            : '.+';
        $pattern .= '/s';

        return preg_replace($pattern, '[redacted]', $message) ?? $message;
    }
}
