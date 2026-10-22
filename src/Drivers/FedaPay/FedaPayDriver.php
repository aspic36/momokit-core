<?php

declare(strict_types=1);

namespace MomoKit\Core\Drivers\FedaPay;

use DateTimeImmutable;
use JsonException;
use MomoKit\Core\Contracts\Driver;
use MomoKit\Core\Contracts\SupportsWebhooks;
use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\DTO\Payment;
use MomoKit\Core\DTO\WebhookEvent;
use MomoKit\Core\Enums\PaymentStatus;
use MomoKit\Core\Enums\WebhookEventType;
use MomoKit\Core\Exceptions\InvalidWebhookSignatureException;
use MomoKit\Core\Exceptions\ProviderRequestException;
use MomoKit\Core\Exceptions\UnsupportedCountryException;
use MomoKit\Core\Support\PhoneNumber;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class FedaPayDriver implements Driver, SupportsWebhooks
{
    public const NAME = 'fedapay';

    /**
     * FedaPay phone numbers are sent as E.164 plus an ISO 3166-1 alpha-2
     * country code. Only the countries served by FedaPay are mapped.
     *
     * @var array<string, string>
     */
    private const DIALING_CODES = [
        '229' => 'bj',
        '225' => 'ci',
        '221' => 'sn',
        '228' => 'tg',
        '226' => 'bf',
        '223' => 'ml',
        '227' => 'ne',
        '224' => 'gn',
        '237' => 'cm',
    ];

    /** @var array<string, PaymentStatus> */
    private const STATUS_MAP = [
        'pending' => PaymentStatus::Pending,
        'approved' => PaymentStatus::Succeeded,
        'declined' => PaymentStatus::Failed,
        'canceled' => PaymentStatus::Cancelled,
        'refunded' => PaymentStatus::Refunded,
        'transferred' => PaymentStatus::Succeeded,
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly FedaPayConfig $config,
        private readonly int $webhookTolerance = 300,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(CollectRequest $request): Payment
    {
        $metadata = $request->metadata;

        if ($request->reference !== null) {
            $metadata['reference'] ??= $request->reference;
        }

        $payload = [
            'description' => $request->description ?? $request->reference ?? 'Paiement mobile money',
            'amount' => $request->amount,
            'currency' => ['iso' => $request->currency],
            'customer' => [
                'phone_number' => [
                    'number' => (string) $request->phone,
                    'country' => $this->countryFor($request->phone),
                ],
            ],
        ];

        if ($metadata !== []) {
            $payload['custom_metadata'] = $metadata;
        }

        $callbackUrl = $request->callbackUrl ?? $this->config->callbackUrl;

        if ($callbackUrl !== null) {
            $payload['callback_url'] = $callbackUrl;
        }

        $created = $this->request('POST', '/transactions', $payload);
        $transactionId = $this->scalarString($created['id'] ?? null);

        if ($transactionId === '') {
            throw ProviderRequestException::invalidResponse(self::NAME, 'transaction id missing');
        }

        $token = $this->request('POST', '/transactions/'.$transactionId.'/token');
        $checkoutUrl = $this->scalarString($token['url'] ?? null);

        return new Payment(
            id: $transactionId,
            driver: self::NAME,
            amount: $request->amount,
            currency: $request->currency,
            phone: (string) $request->phone,
            status: $this->statusFrom($created['status'] ?? null),
            description: $request->description,
            metadata: $request->metadata,
            reference: $request->reference,
            checkoutUrl: $checkoutUrl === '' ? null : $checkoutUrl,
            createdAt: $this->dateFrom($created['created_at'] ?? null),
        );
    }

    public function status(string $transactionId): Payment
    {
        $data = $this->request('GET', '/transactions/'.rawurlencode($transactionId));

        $currency = $data['currency'] ?? 'XOF';

        if (is_array($currency)) {
            $currency = $currency['iso'] ?? 'XOF';
        }

        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $phoneNumber = is_array($customer['phone_number'] ?? null) ? $customer['phone_number'] : [];
        $metadata = is_array($data['custom_metadata'] ?? null) ? $data['custom_metadata'] : [];

        /** @var array<string, mixed> $metadata */
        return new Payment(
            id: $this->scalarString($data['id'] ?? null) ?: $transactionId,
            driver: self::NAME,
            amount: (int) ($data['amount'] ?? 0),
            currency: $this->scalarString($currency) ?: 'XOF',
            phone: $this->scalarString($phoneNumber['number'] ?? null),
            status: $this->statusFrom($data['status'] ?? null),
            description: $this->scalarString($data['description'] ?? null) ?: null,
            metadata: $metadata,
            createdAt: $this->dateFrom($data['created_at'] ?? null),
        );
    }

    public function parseWebhook(string $payload, string $signatureHeader): WebhookEvent
    {
        $this->verifySignature($payload, $signatureHeader);

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidWebhookSignatureException::reason('payload is not valid JSON');
        }

        if (! is_array($data)) {
            throw InvalidWebhookSignatureException::reason('payload is empty');
        }

        /** @var array<string, mixed> $data */
        $name = $this->scalarString($data['name'] ?? $data['event'] ?? $data['type'] ?? null);
        $entity = is_array($data['entity'] ?? null) ? $data['entity'] : [];
        $entityStatus = $this->scalarString($entity['status'] ?? null);

        $type = $this->eventType($name, $entityStatus);

        return new WebhookEvent(
            driver: self::NAME,
            name: $name,
            type: $type,
            transactionId: $this->scalarString($entity['id'] ?? null) ?: null,
            status: $entityStatus !== '' ? (self::STATUS_MAP[$entityStatus] ?? null) : $this->statusForEvent($type),
            payload: $data,
        );
    }

    private function verifySignature(string $payload, string $header): void
    {
        $secret = $this->config->webhookSecret;

        if ($secret === null || $secret === '') {
            throw InvalidWebhookSignatureException::reason('no webhook secret is configured');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', $part, 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && is_numeric($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 's' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null) {
            throw InvalidWebhookSignatureException::reason('timestamp missing from header');
        }

        if ($signatures === []) {
            throw InvalidWebhookSignatureException::reason('signature missing from header');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $matched = false;

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            throw InvalidWebhookSignatureException::reason('signature does not match');
        }

        if ($this->webhookTolerance > 0 && abs(time() - $timestamp) > $this->webhookTolerance) {
            throw InvalidWebhookSignatureException::reason('timestamp is outside the tolerance zone');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->config->baseUrl().$path)
            ->withHeader('Authorization', 'Bearer '.$this->config->secretKey)
            ->withHeader('Accept', 'application/json');

        if ($payload !== []) {
            try {
                $body = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw ProviderRequestException::invalidPayload(self::NAME, $e->getMessage());
            }

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        $response = $this->http->sendRequest($request);
        $statusCode = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw ProviderRequestException::fromResponse(self::NAME, $statusCode, $raw);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ProviderRequestException::invalidResponse(self::NAME, 'response is not valid JSON');
        }

        if (! is_array($data)) {
            throw ProviderRequestException::invalidResponse(self::NAME, 'response is empty');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function countryFor(PhoneNumber $phone): string
    {
        $digits = ltrim($phone->e164, '+');
        $code = substr($digits, 0, 3);

        return self::DIALING_CODES[$code] ?? throw new UnsupportedCountryException(sprintf(
            'FedaPay does not support the phone country code "+%s" (supported: %s).',
            $code,
            implode(', ', array_map(
                static fn (string $dialing, string $iso): string => '+'.$dialing.' ('.$iso.')',
                array_keys(self::DIALING_CODES),
                array_values(self::DIALING_CODES),
            )),
        ));
    }

    private function statusFrom(mixed $status): PaymentStatus
    {
        $status = $this->scalarString($status);

        return self::STATUS_MAP[$status] ?? PaymentStatus::Pending;
    }

    private function eventType(string $name, string $entityStatus): WebhookEventType
    {
        return match ($name) {
            'transaction.approved' => WebhookEventType::PaymentSucceeded,
            'transaction.declined' => WebhookEventType::PaymentFailed,
            'transaction.canceled' => WebhookEventType::PaymentCancelled,
            'transaction.refunded' => WebhookEventType::PaymentRefunded,
            'transaction.created', 'transaction.updated' => $entityStatus === ''
                ? WebhookEventType::PaymentPending
                : $this->eventTypeForStatus($entityStatus),
            default => WebhookEventType::Unknown,
        };
    }

    private function eventTypeForStatus(string $status): WebhookEventType
    {
        return match ($status) {
            'approved', 'transferred' => WebhookEventType::PaymentSucceeded,
            'declined' => WebhookEventType::PaymentFailed,
            'canceled' => WebhookEventType::PaymentCancelled,
            'refunded' => WebhookEventType::PaymentRefunded,
            'pending' => WebhookEventType::PaymentPending,
            default => WebhookEventType::Unknown,
        };
    }

    private function statusForEvent(WebhookEventType $type): ?PaymentStatus
    {
        return match ($type) {
            WebhookEventType::PaymentPending => PaymentStatus::Pending,
            WebhookEventType::PaymentSucceeded => PaymentStatus::Succeeded,
            WebhookEventType::PaymentFailed => PaymentStatus::Failed,
            WebhookEventType::PaymentCancelled => PaymentStatus::Cancelled,
            WebhookEventType::PaymentRefunded => PaymentStatus::Refunded,
            WebhookEventType::Unknown => null,
        };
    }

    private function scalarString(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private function dateFrom(mixed $value): ?DateTimeImmutable
    {
        $value = $this->scalarString($value);

        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
