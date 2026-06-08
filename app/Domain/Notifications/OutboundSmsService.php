<?php

namespace App\Domain\Notifications;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Log;

class OutboundSmsService
{
    /**
     * Send an SMS message. Failures are logged and never thrown.
     */
    public function send(
        string $phone,
        string $message,
        string $messageType,
        ?int $userId = null,
        ?int $applicationId = null,
    ): bool {
        $phone = trim($phone);
        if ($phone === '' || trim($message) === '') {
            return false;
        }

        $provider = (string) config('services.sms.provider', 'log');

        $log = SmsLog::create([
            'user_id' => $userId,
            'application_id' => $applicationId,
            'phone_number' => $phone,
            'message_type' => $messageType,
            'message_body' => $message,
            'provider' => $provider,
            'status' => 'queued',
            'provider_reference' => null,
            'sent_at' => null,
        ]);

        try {
            $this->dispatchToProvider($phone, $message, $provider);

            $log->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();

            return true;
        } catch (\Throwable $e) {
            $log->forceFill(['status' => 'failed'])->save();

            Log::warning('Outbound SMS failed.', [
                'message_type' => $messageType,
                'phone' => $phone,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function dispatchToProvider(string $phone, string $message, string $provider): void
    {
        if ($provider === 'log') {
            Log::info('SMS', [
                'to' => $phone,
                'message' => $message,
            ]);

            return;
        }

        // Future SMS gateways (Twilio, Africa's Talking, etc.) plug in here.
        throw new \RuntimeException("Unsupported SMS provider [{$provider}].");
    }
}
