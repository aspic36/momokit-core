<?php

declare(strict_types=1);

namespace MomoKit\Core\Testing;

use DateTimeImmutable;
use MomoKit\Core\Contracts\Driver;
use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\DTO\Payment;
use MomoKit\Core\Enums\FailureReason;
use MomoKit\Core\Enums\PaymentStatus;
use MomoKit\Core\Exceptions\UnknownTransactionException;

/**
 * In-memory driver that records every call and never touches the network.
 * Created by MobileMoneyFake; use the fake for assertions and status control.
 */
final class FakeDriver implements Driver
{
    /** @var array<string, Payment> */
    private array $payments = [];

    /** @var list<CollectRequest> */
    private array $collects = [];

    /** @var list<string> */
    private array $statusChecks = [];

    private int $counter = 0;

    public function __construct(private readonly string $driverName)
    {
    }

    public function name(): string
    {
        return $this->driverName;
    }

    public function collect(CollectRequest $request): Payment
    {
        $this->collects[] = $request;

        $id = sprintf('%s_fake_%04d', $this->driverName, ++$this->counter);

        return $this->payments[$id] = new Payment(
            id: $id,
            driver: $this->driverName,
            amount: $request->amount,
            currency: $request->currency,
            phone: (string) $request->phone,
            status: PaymentStatus::Pending,
            description: $request->description,
            metadata: $request->metadata,
            reference: $request->reference,
            checkoutUrl: sprintf('https://checkout.momokit.test/%s/%s', $this->driverName, $id),
            createdAt: new DateTimeImmutable(),
        );
    }

    public function status(string $transactionId): Payment
    {
        $this->statusChecks[] = $transactionId;

        return $this->payment($transactionId);
    }

    /**
     * @throws UnknownTransactionException
     */
    public function payment(string $transactionId): Payment
    {
        return $this->payments[$transactionId] ?? throw UnknownTransactionException::forId($transactionId);
    }

    public function hasPayment(string $transactionId): bool
    {
        return isset($this->payments[$transactionId]);
    }

    /**
     * @throws UnknownTransactionException
     */
    public function markAs(string $transactionId, PaymentStatus $status, ?FailureReason $failureReason = null): Payment
    {
        $payment = $this->payment($transactionId);

        $failureReason ??= match ($status) {
            PaymentStatus::Failed => FailureReason::ProviderError,
            PaymentStatus::Cancelled => FailureReason::CancelledByCustomer,
            default => null,
        };

        return $this->payments[$transactionId] = new Payment(
            id: $payment->id,
            driver: $payment->driver,
            amount: $payment->amount,
            currency: $payment->currency,
            phone: $payment->phone,
            status: $status,
            failureReason: $failureReason,
            description: $payment->description,
            metadata: $payment->metadata,
            reference: $payment->reference,
            checkoutUrl: $payment->checkoutUrl,
            createdAt: $payment->createdAt,
        );
    }

    /**
     * @return list<CollectRequest>
     */
    public function collects(): array
    {
        return $this->collects;
    }

    /**
     * @return list<string>
     */
    public function statusChecks(): array
    {
        return $this->statusChecks;
    }
}
