<?php

namespace Tests\Unit;

use App\Domain\Payments\Gateways\CGrate\CGratePaymentGateway;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CGratePaymentGatewayAmountFormatTest extends TestCase
{
    public function test_amount_format_kwacha_decimal_sends_two_decimal_places(): void
    {
        config([
            'cgrate.enabled' => true,
            'cgrate.username' => 'u',
            'cgrate.password' => 'p',
            'cgrate.base_url' => 'https://example.test',
            'cgrate.soap.endpoint_path' => '/Konik/KonikWs',
            'cgrate.soap.namespace' => 'http://konik.cgrate.com',
            'cgrate.amount_mode' => 'kwacha_decimal',
            'cgrate.msisdn_format' => 'local',
        ]);

        Http::fake([
            'https://example.test/Konik/KonikWs' => Http::response($this->soapReturn(0, 'SUCCESS'), 200),
        ]);

        $payment = new Payment();
        $payment->id = 1;
        $payment->invoice_id = 2;
        $payment->amount_cents = 1050;
        $payment->currency = 'ZMW';

        $gateway = app(CGratePaymentGateway::class);
        $gateway->initiate($payment, PaymentMethod::MobileMoney, [
            'mobile_number' => '0970000000',
            'payment_reference' => 'ZAQA-REF-AMT',
        ]);

        Http::assertSent(function (Request $request) {
            $this->assertStringContainsString('<transactionAmount>10.50</transactionAmount>', $request->body());

            return true;
        });
    }

    public function test_amount_format_minor_units_sends_integer_minor_units(): void
    {
        config([
            'cgrate.enabled' => true,
            'cgrate.username' => 'u',
            'cgrate.password' => 'p',
            'cgrate.base_url' => 'https://example.test',
            'cgrate.soap.endpoint_path' => '/Konik/KonikWs',
            'cgrate.soap.namespace' => 'http://konik.cgrate.com',
            'cgrate.amount_mode' => 'minor_units',
            'cgrate.msisdn_format' => 'local',
        ]);

        Http::fake([
            'https://example.test/Konik/KonikWs' => Http::response($this->soapReturn(0, 'SUCCESS'), 200),
        ]);

        $payment = new Payment();
        $payment->id = 1;
        $payment->invoice_id = 2;
        $payment->amount_cents = 1050;
        $payment->currency = 'ZMW';

        $gateway = app(CGratePaymentGateway::class);
        $gateway->initiate($payment, PaymentMethod::MobileMoney, [
            'mobile_number' => '0970000000',
            'payment_reference' => 'ZAQA-REF-AMT2',
        ]);

        Http::assertSent(function (Request $request) {
            $this->assertStringContainsString('<transactionAmount>1050</transactionAmount>', $request->body());

            return true;
        });
    }

    private function soapReturn(?int $code, string $message, ?string $paymentId = null): string
    {
        $codeXml = $code === null ? '' : '<responseCode>'.$code.'</responseCode>';
        $pidXml = $paymentId ? '<paymentID>'.$paymentId.'</paymentID>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soapenv:Body>'
            .'<ns2:processCustomerPaymentResponse xmlns:ns2="http://konik.cgrate.com">'
            .'<return>'
            .$codeXml
            .'<responseMessage>'.$message.'</responseMessage>'
            .$pidXml
            .'</return>'
            .'</ns2:processCustomerPaymentResponse>'
            .'</soapenv:Body>'
            .'</soapenv:Envelope>';
    }
}

