<?php

declare(strict_types=1);

namespace MomoKit\Core\Tests\Drivers;

use MomoKit\Core\Drivers\FedaPay\FedaPayConfig;
use MomoKit\Core\Drivers\FedaPay\FedaPayDriver;
use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\Enums\PaymentStatus;
use MomoKit\Core\Enums\WebhookEventType;
use MomoKit\Core\Exceptions\InvalidWebhookSignatureException;
use MomoKit\Core\Exceptions\ProviderRequestException;
use MomoKit\Core\Exceptions\UnsupportedCountryException;
use MomoKit\Core\Tests\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class FedaPayDriverTest extends TestCase
{
    private FakeHttpClient $http;

    private Psr17Factory $factory;

    private FedaPayDriver $driver;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->factory = new Psr17Factory();

        $this->driver = new FedaPayDriver(
            $this->http,
            $this->factory,
            $this->factory,
            new FedaPayConfig(
                secretKey: 'sk_sandbox_test',
                environment: 'sandbox',
                webhookSecret: 'wh_sandbox_test',
                callbackUrl: 'https://example.com/callback',
            ),
        );
    }

    public function test_name_is_fedapay(): void
    {
        $this->assertSame('fedapay', $this->driver->name());
    }

    public function test_collect_creates_a_transaction_and_returns_a_checkout_url(): void
    {
        $this->http->queueResponse($this->json(201, [
            'id' => 258,
            'reference' => 'TRX-258',
            'amount' => 5000,
            'status' => 'pending',
            'created_at' => '2026-10-22T10:00:00.000000Z',
        ]));
        $this->http->queueResponse($this->json(200, [
            'token' => 'tok_123',
            'url' => 'https://sandbox-checkout.fedapay.com/pay/tok_123',
        ]));

        $payment = $this->driver->collect(
            CollectRequest::make(5000, 'XOF', '+229 97 00 00 01', 'Commande #42', ['order_id' => 42]),
        );

        $this->assertSame('258', $payment->id);
        $this->assertSame('fedapay', $payment->driver);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('https://sandbox-checkout.fedapay.com/pay/tok_123', $payment->checkoutUrl);
        $this->assertSame('Commande #42', $payment->description);

        $create = $this->http->requests[0];
        $this->assertSame('POST', $create->getMethod());
        $this->assertSame('https://sandbox-api.fedapay.com/v1/transactions', (string) $create->getUri());
        $this->assertSame('Bearer sk_sandbox_test', $create->getHeaderLine('Authorization'));

        $body = json_decode((string) $create->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(5000, $body['amount']);
        $this->assertSame(['iso' => 'XOF'], $body['currency']);
        $this->assertSame('Commande #42', $body['description']);
        $this->assertSame('+22997000001', $body['customer']['phone_number']['number']);
        $this->assertSame('bj', $body['customer']['phone_number']['country']);
        $this->assertSame(['order_id' => 42], $body['custom_metadata']);
        $this->assertSame('https://example.com/callback', $body['callback_url']);

        $tokenRequest = $this->http->requests[1];
        $this->assertSame('POST', $tokenRequest->getMethod());
        $this->assertSame('https://sandbox-api.fedapay.com/v1/transactions/258/token', (string) $tokenRequest->getUri());
    }

    public function test_collect_maps_the_ivory_coast_country_code(): void
    {
        $this->http->queueResponse($this->json(201, ['id' => 1, 'status' => 'pending']));
        $this->http->queueResponse($this->json(200, ['url' => 'https://example.com/pay']));

        $this->driver->collect(CollectRequest::make(1000, 'XOF', '+225 07 00 00 00 00'));

        $body = json_decode((string) $this->http->requests[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ci', $body['customer']['phone_number']['country']);
    }

    public function test_collect_rejects_unsupported_countries(): void
    {
        $this->expectException(UnsupportedCountryException::class);

        $this->driver->collect(CollectRequest::make(1000, 'XOF', '+254 712 345 678'));
    }

    public function test_collect_throws_on_http_errors(): void
    {
        $this->http->queueResponse($this->json(401, ['message' => 'Unauthorized']));

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->driver->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));
    }

    public function test_status_maps_an_approved_transaction(): void
    {
        $this->http->queueResponse($this->json(200, [
            'id' => 258,
            'amount' => 5000,
            'currency' => ['iso' => 'XOF'],
            'status' => 'approved',
            'description' => 'Commande #42',
            'custom_metadata' => ['order_id' => 42],
            'customer' => ['phone_number' => ['number' => '+22997000001', 'country' => 'bj']],
            'created_at' => '2026-10-22T10:00:00.000000Z',
        ]));

        $payment = $this->driver->status('258');

        $this->assertSame('258', $payment->id);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertTrue($payment->succeeded());
        $this->assertSame(5000, $payment->amount);
        $this->assertSame('XOF', $payment->currency);
        $this->assertSame('+22997000001', $payment->phone);
        $this->assertSame('GET', $this->http->requests[0]->getMethod());
        $this->assertSame('https://sandbox-api.fedapay.com/v1/transactions/258', (string) $this->http->requests[0]->getUri());
    }

    public function test_status_maps_declined_and_canceled_transactions(): void
    {
        $this->http->queueResponse($this->json(200, ['id' => 1, 'status' => 'declined']));
        $this->http->queueResponse($this->json(200, ['id' => 2, 'status' => 'canceled']));

        $this->assertSame(PaymentStatus::Failed, $this->driver->status('1')->status);
        $this->assertSame(PaymentStatus::Cancelled, $this->driver->status('2')->status);
    }

    public function test_webhook_approved_event_is_normalized(): void
    {
        $payload = $this->webhookPayload('transaction.approved', 'approved', 258);

        $event = $this->driver->parseWebhook($payload, $this->signature($payload));

        $this->assertSame('transaction.approved', $event->name);
        $this->assertSame(WebhookEventType::PaymentSucceeded, $event->type);
        $this->assertSame(PaymentStatus::Succeeded, $event->status);
        $this->assertSame('258', $event->transactionId);
        $this->assertSame('fedapay', $event->driver);
    }

    public function test_webhook_declined_and_canceled_events_are_normalized(): void
    {
        $declined = $this->webhookPayload('transaction.declined', 'declined', 1);
        $canceled = $this->webhookPayload('transaction.canceled', 'canceled', 2);

        $this->assertSame(WebhookEventType::PaymentFailed, $this->driver->parseWebhook($declined, $this->signature($declined))->type);
        $this->assertSame(WebhookEventType::PaymentCancelled, $this->driver->parseWebhook($canceled, $this->signature($canceled))->type);
    }

    public function test_webhook_updated_event_uses_the_entity_status(): void
    {
        $payload = $this->webhookPayload('transaction.updated', 'approved', 258);

        $event = $this->driver->parseWebhook($payload, $this->signature($payload));

        $this->assertSame(WebhookEventType::PaymentSucceeded, $event->type);
    }

    public function test_webhook_with_an_invalid_signature_is_rejected(): void
    {
        $payload = $this->webhookPayload('transaction.approved', 'approved', 258);

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->driver->parseWebhook($payload, 't='.time().',s=deadbeef');
    }

    public function test_webhook_with_an_old_timestamp_is_rejected(): void
    {
        $payload = $this->webhookPayload('transaction.approved', 'approved', 258);

        $this->expectException(InvalidWebhookSignatureException::class);
        $this->expectExceptionMessage('tolerance');

        $this->driver->parseWebhook($payload, $this->signature($payload, time() - 1000));
    }

    public function test_webhook_without_a_configured_secret_is_rejected(): void
    {
        $driver = new FedaPayDriver(
            $this->http,
            $this->factory,
            $this->factory,
            new FedaPayConfig(secretKey: 'sk_sandbox_test'),
        );

        $payload = $this->webhookPayload('transaction.approved', 'approved', 258);

        $this->expectException(InvalidWebhookSignatureException::class);

        $driver->parseWebhook($payload, $this->signature($payload));
    }

    public function test_live_environment_uses_the_live_base_url(): void
    {
        $driver = new FedaPayDriver(
            $this->http,
            $this->factory,
            $this->factory,
            new FedaPayConfig(secretKey: 'sk_live_test', environment: 'live'),
        );

        $this->http->queueResponse($this->json(200, ['id' => 1, 'status' => 'pending']));

        $driver->status('1');

        $this->assertSame('https://api.fedapay.com/v1/transactions/1', (string) $this->http->requests[0]->getUri());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function webhookPayload(string $name, string $status, int $id): string
    {
        return json_encode([
            'id' => 1,
            'name' => $name,
            'entity' => [
                'id' => $id,
                'status' => $status,
                'amount' => 5000,
                'custom_metadata' => ['order_id' => 42],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function signature(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$payload, 'wh_sandbox_test');
    }
}
