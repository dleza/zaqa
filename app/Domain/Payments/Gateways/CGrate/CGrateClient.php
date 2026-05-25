<?php

namespace App\Domain\Payments\Gateways\CGrate;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class CGrateClient
{
    public function processCustomerPayment(string $transactionAmount, string $customerMobile, string $paymentReference): CGratePaymentResponse
    {
        $xml = $this->buildEnvelope('processCustomerPayment', [
            'transactionAmount' => $transactionAmount,
            'customerMobile' => $customerMobile,
            'paymentReference' => $paymentReference,
        ]);

        return $this->send(operation: 'processCustomerPayment', requestXml: $xml, safeRequest: [
            'transactionAmount' => $transactionAmount,
            'customerMobile' => $customerMobile,
            'paymentReference' => $paymentReference,
        ]);
    }

    public function queryCustomerPayment(string $paymentReference): CGratePaymentResponse
    {
        $xml = $this->buildEnvelope('queryCustomerPayment', [
            'paymentReference' => $paymentReference,
        ]);

        return $this->send(operation: 'queryCustomerPayment', requestXml: $xml, safeRequest: [
            'paymentReference' => $paymentReference,
        ]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function buildEnvelope(string $operation, array $fields): string
    {
        $ns = (string) config('cgrate.soap.namespace', 'http://konik.cgrate.com');
        $username = (string) config('cgrate.username');
        $password = (string) config('cgrate.password');

        if (trim($username) === '' || trim($password) === '') {
            throw new CGrateException('cGrate credentials are not configured.');
        }

        $body = '';
        foreach ($fields as $key => $value) {
            $body .= '<'.$key.'>'.$this->escape($value).'</'.$key.'>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:kon="'.$this->escapeAttr($ns).'">'
            .'<soapenv:Header>'
            .'<wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" soapenv:mustUnderstand="1">'
            .'<wsse:UsernameToken xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd" wsu:Id="UsernameToken-1">'
            .'<wsse:Username>'.$this->escape($username).'</wsse:Username>'
            .'<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">'.$this->escape($password).'</wsse:Password>'
            .'</wsse:UsernameToken>'
            .'</wsse:Security>'
            .'</soapenv:Header>'
            .'<soapenv:Body>'
            .'<kon:'.$operation.'>'.$body.'</kon:'.$operation.'>'
            .'</soapenv:Body>'
            .'</soapenv:Envelope>';
    }

    /**
     * @param array<string, mixed> $safeRequest
     */
    private function send(string $operation, string $requestXml, array $safeRequest): CGratePaymentResponse
    {
        $baseUrl = rtrim((string) config('cgrate.base_url'), '/');
        $path = (string) config('cgrate.soap.endpoint_path', '/Konik/KonikWs');
        $url = $baseUrl.$path;

        $timeout = (int) config('cgrate.timeout', 30);
        $connectTimeout = (int) config('cgrate.connect_timeout', 10);
        $verifySsl = (bool) config('cgrate.verify_ssl', true);
        $contentType = (string) config('cgrate.soap.content_type', 'application/soap+xml; charset=utf-8');

        try {
            $pending = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders([
                    'Content-Type' => $contentType,
                    // Some SOAP 1.1 servers require SOAPAction; keep empty unless configured.
                    'SOAPAction' => '',
                ])
                ->withBody($requestXml, $contentType);

            if (! $verifySsl) {
                $pending = $pending->withoutVerifying();
            }

            $response = $pending->post($url);
        } catch (ConnectionException $e) {
            throw new CGrateException('cGrate connection failed (timeout / network error).', [
                'operation' => $operation,
                'url' => $url,
                'request' => $safeRequest,
            ], previous: $e);
        }

        $body = (string) $response->body();
        if (trim($body) === '') {
            throw new CGrateException('cGrate returned an empty response body.', [
                'operation' => $operation,
                'url' => $url,
                'http_status' => $response->status(),
                'request' => $safeRequest,
            ]);
        }

        $parsed = $this->parseXmlResponse($body);

        return new CGratePaymentResponse(
            responseCode: $parsed['response_code'],
            responseMessage: $parsed['response_message'],
            paymentId: $parsed['payment_id'],
            raw: [
                'operation' => $operation,
                'http_status' => $response->status(),
                'request' => $safeRequest,
                'response' => [
                    'responseCode' => $parsed['response_code'],
                    'responseMessage' => $parsed['response_message'],
                    'paymentID' => $parsed['payment_id'],
                ],
            ],
        );
    }

    /**
     * @return array{response_code: int|null, response_message: string, payment_id: string|null}
     */
    private function parseXmlResponse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            $doc->validateOnParse = false;

            if (! $doc->loadXML($xml, LIBXML_NONET)) {
                $errors = array_map(
                    fn ($e) => trim($e->message),
                    libxml_get_errors() ?: [],
                );
                libxml_clear_errors();

                throw new CGrateException('Invalid XML returned by cGrate.', [
                    'errors' => array_values(array_filter($errors)),
                ]);
            }

            $xpath = new \DOMXPath($doc);

            $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);
            if ($fault) {
                $faultString = $xpath->query('.//*[local-name()="faultstring" or local-name()="Reason"]', $fault)->item(0);
                $faultText = $faultString?->textContent ? trim((string) $faultString->textContent) : 'SOAP Fault';

                throw new CGrateException('cGrate SOAP fault: '.$faultText);
            }

            $return = $xpath->query('//*[local-name()="return"]')->item(0);
            if (! $return) {
                throw new CGrateException('cGrate response missing <return> node.');
            }

            $codeNode = $xpath->query('.//*[local-name()="responseCode"]', $return)->item(0);
            $msgNode = $xpath->query('.//*[local-name()="responseMessage"]', $return)->item(0);
            $pidNode = $xpath->query('.//*[local-name()="paymentID"]', $return)->item(0);

            $code = null;
            if ($codeNode && trim((string) $codeNode->textContent) !== '') {
                $code = (int) trim((string) $codeNode->textContent);
            }

            $message = $msgNode?->textContent ? trim((string) $msgNode->textContent) : '';
            $paymentId = $pidNode?->textContent ? trim((string) $pidNode->textContent) : null;

            return [
                'response_code' => $code,
                'response_message' => $message,
                'payment_id' => $paymentId !== '' ? $paymentId : null,
            ];
        } finally {
            libxml_use_internal_errors($prev);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
