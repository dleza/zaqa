<?php

namespace Tests\Unit;

use App\Domain\Payments\Gateways\CGrate\CGrateClient;
use App\Domain\Payments\Gateways\CGrate\CGrateException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CGrateClientSoapTest extends TestCase
{
    public function test_process_customer_payment_sends_expected_soap_envelope_and_parses_response(): void
    {
        config([
            'cgrate.username' => 'test-user',
            'cgrate.password' => 'test-pass',
            'cgrate.base_url' => 'https://example.test',
            'cgrate.soap.endpoint_path' => '/Konik/KonikWs',
            'cgrate.soap.namespace' => 'http://konik.cgrate.com',
            'cgrate.soap.content_type' => 'application/soap+xml; charset=utf-8',
            'cgrate.timeout' => 5,
            'cgrate.connect_timeout' => 5,
        ]);

        Http::fake([
            'https://example.test/Konik/KonikWs' => Http::response($this->soapReturn(0, 'SUCCESS', 'PID-123'), 200, [
                'Content-Type' => 'text/xml; charset=utf-8',
            ]),
        ]);

        $client = app(CGrateClient::class);
        $resp = $client->processCustomerPayment('10.00', '0970000000', 'ZAQA-REF-1');

        $this->assertSame(0, $resp->responseCode);
        $this->assertSame('SUCCESS', $resp->responseMessage);
        $this->assertSame('PID-123', $resp->paymentId);

        $this->assertSame('ZAQA-REF-1', $resp->raw['request']['paymentReference'] ?? null);
        $this->assertArrayNotHasKey('password', $resp->raw['request'] ?? []);

        Http::assertSent(function (Request $request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://example.test/Konik/KonikWs', $request->url());

            $this->assertSame('application/soap+xml; charset=utf-8', $request->header('Content-Type')[0] ?? null);

            $body = $request->body();
            $this->assertStringContainsString('<kon:processCustomerPayment>', $body);
            $this->assertStringContainsString('<transactionAmount>10.00</transactionAmount>', $body);
            $this->assertStringContainsString('<customerMobile>0970000000</customerMobile>', $body);
            $this->assertStringContainsString('<paymentReference>ZAQA-REF-1</paymentReference>', $body);

            // WS-Security UsernameToken (PasswordText).
            $this->assertStringContainsString('<wsse:UsernameToken', $body);
            $this->assertStringContainsString('<wsse:Username>test-user</wsse:Username>', $body);
            $this->assertStringContainsString('#PasswordText', $body);
            $this->assertStringContainsString('<wsse:Password', $body);

            return true;
        });
    }

    public function test_query_customer_payment_parses_fault_as_exception(): void
    {
        config([
            'cgrate.username' => 'test-user',
            'cgrate.password' => 'test-pass',
            'cgrate.base_url' => 'https://example.test',
            'cgrate.soap.endpoint_path' => '/Konik/KonikWs',
            'cgrate.soap.namespace' => 'http://konik.cgrate.com',
        ]);

        Http::fake([
            'https://example.test/Konik/KonikWs' => Http::response($this->soapFault('InvalidSecurity'), 500, [
                'Content-Type' => 'text/xml; charset=utf-8',
            ]),
        ]);

        $client = app(CGrateClient::class);

        $this->expectException(CGrateException::class);
        $this->expectExceptionMessage('SOAP fault');

        $client->queryCustomerPayment('ZAQA-REF-FAULT');
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

    private function soapFault(string $faultString): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soapenv:Body>'
            .'<soapenv:Fault>'
            .'<faultcode>soapenv:Server</faultcode>'
            .'<faultstring>'.$faultString.'</faultstring>'
            .'</soapenv:Fault>'
            .'</soapenv:Body>'
            .'</soapenv:Envelope>';
    }
}

