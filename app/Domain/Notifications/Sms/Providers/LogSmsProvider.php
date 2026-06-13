<?php

namespace App\Domain\Notifications\Sms\Providers;

use App\Domain\Notifications\Sms\Data\SmsSendResult;
use Illuminate\Support\Facades\Log;

final class LogSmsProvider implements SmsProviderInterface
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $contacts, string $message): SmsSendResult
    {
        Log::info('SMS (log provider)', [
            'to' => $contacts,
            'message' => $message,
            'length' => mb_strlen($message),
        ]);

        return new SmsSendResult(
            accepted: true,
            httpStatus: 202,
            providerSuccess: true,
            providerMessage: 'Logged locally.',
            providerReference: null,
            sanitizedResponse: [
                'success' => true,
                'message' => 'Logged locally.',
            ],
        );
    }

    public function healthCheck(): array
    {
        return [
            'ok' => true,
            'message' => 'Log SMS provider is configured.',
            'details' => [
                'provider' => 'log',
            ],
        ];
    }
}
