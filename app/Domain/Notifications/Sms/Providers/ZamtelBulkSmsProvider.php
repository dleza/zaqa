<?php

namespace App\Domain\Notifications\Sms\Providers;

use App\Domain\Notifications\Sms\Data\SmsSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ZamtelBulkSmsProvider implements SmsProviderInterface
{
    public function name(): string
    {
        return 'zamtel';
    }

    public function send(string $contacts, string $message): SmsSendResult
    {
        $apiKey = trim((string) config('sms.zamtel.api_key'));
        $senderId = trim((string) config('sms.zamtel.sender_id'));

        if ($apiKey === '' || $senderId === '') {
            return new SmsSendResult(
                accepted: false,
                httpStatus: 0,
                providerSuccess: false,
                providerMessage: 'Zamtel SMS credentials are not configured.',
                providerReference: null,
                sanitizedResponse: ['success' => false, 'message' => 'Missing API credentials.'],
                failureReason: 'missing_credentials',
                transientFailure: false,
            );
        }

        $baseUrl = rtrim((string) config('sms.zamtel.base_url'), '/');
        $url = sprintf(
            '%s/api/v2.1/action/send/api_key/%s/contacts/%s/senderId/%s/message/%s',
            $baseUrl,
            rawurlencode($apiKey),
            rawurlencode($contacts),
            rawurlencode($senderId),
            rawurlencode($message),
        );

        $timeout = (int) config('sms.zamtel.timeout', 30);
        $connectTimeout = (int) config('sms.zamtel.connect_timeout', 10);

        try {
            $response = Http::withOptions([
                'verify' => (bool) config('sms.zamtel.verify_ssl', true),
            ])
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $e) {
            return new SmsSendResult(
                accepted: false,
                httpStatus: 0,
                providerSuccess: false,
                providerMessage: 'Connection failed.',
                providerReference: null,
                sanitizedResponse: ['success' => false, 'message' => 'Connection failed.'],
                failureReason: 'connection_error',
                transientFailure: true,
            );
        }

        $httpStatus = $response->status();
        $parsed = $this->parseBody($response->body());
        $sanitized = $this->sanitizeResponse($parsed, $apiKey);
        $providerSuccess = (bool) data_get($parsed, 'success', false);
        $accepted = in_array($httpStatus, [200, 202], true) && $providerSuccess;

        $transient = ! $accepted && ($httpStatus === 0 || $httpStatus >= 500 || $response->serverError());

        return new SmsSendResult(
            accepted: $accepted,
            httpStatus: $httpStatus,
            providerSuccess: $providerSuccess,
            providerMessage: is_string(data_get($parsed, 'message')) ? (string) data_get($parsed, 'message') : null,
            providerReference: $this->extractReference($parsed),
            sanitizedResponse: $sanitized,
            failureReason: $accepted ? null : ($providerSuccess ? 'unexpected_status' : 'provider_rejected'),
            transientFailure: $transient,
        );
    }

    public function healthCheck(): array
    {
        $apiKey = trim((string) config('sms.zamtel.api_key'));
        $senderId = trim((string) config('sms.zamtel.sender_id'));
        $baseUrl = trim((string) config('sms.zamtel.base_url'));

        if ($apiKey === '' || $senderId === '') {
            return [
                'ok' => false,
                'message' => 'Zamtel API key or sender ID is not configured.',
                'details' => [
                    'provider' => 'zamtel',
                    'api_key_configured' => $apiKey !== '',
                    'sender_id_configured' => $senderId !== '',
                    'base_url' => $baseUrl,
                ],
            ];
        }

        $timeout = min(10, (int) config('sms.zamtel.timeout', 30));

        try {
            $response = Http::withOptions([
                'verify' => (bool) config('sms.zamtel.verify_ssl', true),
            ])
                ->connectTimeout(5)
                ->timeout($timeout)
                ->get(rtrim($baseUrl, '/'));
        } catch (ConnectionException) {
            return [
                'ok' => false,
                'message' => 'Could not reach Zamtel Bulk SMS host.',
                'details' => [
                    'provider' => 'zamtel',
                    'base_url' => $baseUrl,
                ],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Zamtel Bulk SMS host is reachable and credentials are configured.',
            'details' => [
                'provider' => 'zamtel',
                'base_url' => $baseUrl,
                'sender_id' => $senderId,
                'probe_http_status' => $response->status(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['message' => mb_substr($body, 0, 500)];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function sanitizeResponse(array $parsed, string $apiKey): array
    {
        $json = json_encode($parsed) ?: '';
        if ($apiKey !== '') {
            $json = str_replace($apiKey, '[REDACTED]', $json);
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['success' => data_get($parsed, 'success')];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function extractReference(array $parsed): ?string
    {
        foreach (['reference', 'messageId', 'message_id', 'id'] as $key) {
            $value = data_get($parsed, $key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
