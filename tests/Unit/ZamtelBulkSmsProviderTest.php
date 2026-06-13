<?php

namespace Tests\Unit;

use App\Domain\Notifications\Sms\Providers\ZamtelBulkSmsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZamtelBulkSmsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sms.zamtel.api_key' => 'secret-api-key-123',
            'sms.zamtel.sender_id' => 'ZAQA',
            'sms.zamtel.base_url' => 'https://bulksms.zamtel.co.zm',
        ]);
    }

    public function test_http_202_with_success_true_is_accepted(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true, 'message' => 'Accepted'], 202),
        ]);

        $result = app(ZamtelBulkSmsProvider::class)->send('[260977000001]', 'Hello');

        $this->assertTrue($result->accepted);
        $this->assertSame(202, $result->httpStatus);
        $this->assertTrue($result->providerSuccess);
    }

    public function test_http_200_with_success_true_is_accepted(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true, 'message' => 'OK'], 200),
        ]);

        $result = app(ZamtelBulkSmsProvider::class)->send('[260977000001]', 'Hello');

        $this->assertTrue($result->accepted);
        $this->assertSame(200, $result->httpStatus);
        $this->assertTrue($result->providerSuccess);
    }

    public function test_http_202_with_success_false_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response(['success' => false, 'message' => 'Rejected'], 202),
        ]);

        $result = app(ZamtelBulkSmsProvider::class)->send('[260977000001]', 'Hello');

        $this->assertFalse($result->accepted);
        $this->assertSame(202, $result->httpStatus);
        $this->assertFalse($result->providerSuccess);
    }

    public function test_non_accepted_http_status_is_rejected_even_when_success_true(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 500),
        ]);

        $result = app(ZamtelBulkSmsProvider::class)->send('[260977000001]', 'Hello');

        $this->assertFalse($result->accepted);
        $this->assertSame(500, $result->httpStatus);
    }

    public function test_sanitized_response_never_contains_api_key(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'message' => 'secret-api-key-123 accepted',
            ], 202),
        ]);

        $result = app(ZamtelBulkSmsProvider::class)->send('[260977000001]', 'Hello');
        $encoded = json_encode($result->sanitizedResponse) ?: '';

        $this->assertStringNotContainsString('secret-api-key-123', $encoded);
    }
}
