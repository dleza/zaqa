<?php

namespace App\Domain\Payments;

use Illuminate\Support\Facades\App;

class PaymentGatewayManager
{
    public function gateway(string $provider): PaymentGateway
    {
        $provider = $provider !== '' ? $provider : 'test';

        return match ($provider) {
            'test' => App::make(TestPaymentGateway::class),
            default => App::make(TestPaymentGateway::class),
        };
    }
}

