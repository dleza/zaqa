<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Sms\SmsBalanceService;
use App\Domain\Notifications\Sms\SmsMessageValidator;
use App\Domain\Notifications\Sms\SmsPhoneNormalizer;
use App\Jobs\Notifications\SendSmsJob;
use App\Models\SmsLog;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Support\Facades\Log;

class OutboundSmsService
{
    public function __construct(
        private readonly SmsMessageValidator $validator,
        private readonly SmsPhoneNormalizer $phoneNormalizer,
        private readonly SmsBalanceService $balance,
    ) {
    }

    /**
     * Queue an SMS from a centralized template. Failures are logged and never thrown.
     *
     * @param  array<string, string>  $placeholders
     */
    public function queueTemplate(
        string $templateKey,
        array $placeholders,
        string $phone,
        ?int $userId = null,
        ?int $applicationId = null,
    ): bool {
        $phone = trim($phone);
        if ($phone === '') {
            return false;
        }

        if (! (bool) config('sms.enabled')) {
            $this->createSkippedLog($templateKey, $phone, $userId, $applicationId, 'disabled', '');

            return false;
        }

        if (! $this->balance->isSendingAllowed()) {
            $this->createSkippedLog($templateKey, $phone, $userId, $applicationId, 'insufficient_balance', '');

            return false;
        }

        if (! $this->phoneNormalizer->isValid($phone)) {
            $this->createSkippedLog($templateKey, $phone, $userId, $applicationId, 'invalid_phone', '');

            return false;
        }

        try {
            $message = $this->validator->renderTemplate($templateKey, $placeholders);
        } catch (\InvalidArgumentException $e) {
            $this->createSkippedLog($templateKey, $phone, $userId, $applicationId, 'too_long', '');

            Log::warning('SMS template rejected.', [
                'template_key' => $templateKey,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        try {
            $normalizedStorage = $this->phoneNormalizer->normalizeForStorage($phone);
            $providerContacts = $this->phoneNormalizer->normalizeForZamtel($phone);
        } catch (\InvalidArgumentException) {
            $this->createSkippedLog($templateKey, $phone, $userId, $applicationId, 'invalid_phone', $message ?? '');

            return false;
        }

        $provider = (string) config('sms.provider', 'log');

        $log = SmsLog::create([
            'user_id' => $userId,
            'application_id' => $applicationId,
            'phone_number' => $phone,
            'normalized_phone' => $providerContacts,
            'message_type' => $templateKey,
            'message_body' => $message,
            'message_length' => mb_strlen($message),
            'provider' => $provider,
            'status' => 'queued',
            'provider_reference' => null,
            'sent_at' => null,
        ]);

        try {
            SendSmsJob::dispatch($log->id)->onQueue(NotificationQueue::sms());

            return true;
        } catch (\Throwable $e) {
            $log->forceFill(['status' => 'failed'])->save();

            Log::warning('Outbound SMS queue dispatch failed.', [
                'template_key' => $templateKey,
                'phone' => $normalizedStorage,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function createSkippedLog(
        string $templateKey,
        string $phone,
        ?int $userId,
        ?int $applicationId,
        string $skipReason,
        string $message,
    ): void {
        SmsLog::create([
            'user_id' => $userId,
            'application_id' => $applicationId,
            'phone_number' => $phone,
            'normalized_phone' => null,
            'message_type' => $templateKey,
            'message_body' => $message !== '' ? $message : '[skipped before render]',
            'message_length' => $message !== '' ? mb_strlen($message) : null,
            'provider' => (string) config('sms.provider', 'log'),
            'status' => 'skipped',
            'skip_reason' => $skipReason,
            'provider_reference' => null,
            'sent_at' => null,
        ]);
    }
}
