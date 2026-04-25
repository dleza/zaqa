<?php

namespace App\Domain\Identity\Events;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PhoneOtpIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $code,
        public readonly CarbonImmutable $expiresAt,
    ) {
    }
}

