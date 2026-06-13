<?php

namespace App\Domain\Notifications\Sms;

use App\Domain\Notifications\Sms\Providers\LogSmsProvider;
use App\Domain\Notifications\Sms\Providers\SmsProviderInterface;
use App\Domain\Notifications\Sms\Providers\ZamtelBulkSmsProvider;

final class SmsProviderManager
{
    public function resolve(?string $provider = null): SmsProviderInterface
    {
        $name = $provider ?? (string) config('sms.provider', 'log');

        return match ($name) {
            'zamtel' => app(ZamtelBulkSmsProvider::class),
            'log' => app(LogSmsProvider::class),
            default => throw new \RuntimeException("Unsupported SMS provider [{$name}]."),
        };
    }
}
