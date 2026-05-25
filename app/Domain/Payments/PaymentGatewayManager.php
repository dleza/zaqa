<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Gateways\CGrate\CGratePaymentGateway;
use Illuminate\Support\Facades\App;

class PaymentGatewayManager
{
    public function gateway(string $provider): PaymentGateway
    {
        $provider = $provider !== '' ? $provider : 'test';

        return match ($provider) {
            'test' => App::make(TestPaymentGateway::class),
            'cgrate' => App::make(CGratePaymentGateway::class),
            default => App::make(TestPaymentGateway::class),
        };
    }
}
