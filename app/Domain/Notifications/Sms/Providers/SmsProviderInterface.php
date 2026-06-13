<?php

namespace App\Domain\Notifications\Sms\Providers;

use App\Domain\Notifications\Sms\Data\SmsSendResult;

interface SmsProviderInterface
{
    public function name(): string;

    public function send(string $contacts, string $message): SmsSendResult;

    /**
     * @return array{ok: bool, message: string, details: array<string, mixed>}
     */
    public function healthCheck(): array;
}
