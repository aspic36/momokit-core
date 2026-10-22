<?php

declare(strict_types=1);

namespace MomoKit\Core\Drivers\Mock;

use DateTimeImmutable;
use MomoKit\Core\Contracts\Driver;
use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\DTO\Payment;
use MomoKit\Core\Enums\FailureReason;
use MomoKit\Core\Enums\PaymentStatus;
use MomoKit\Core\Exceptions\PaymentTimeoutException;
use MomoKit\Core\Exceptions\UnknownTransactionException;

/**
 * Local simulator: lets developers build and test their payment flow
 * without a merchant account, real money or network access.
 */
final class MockDriver implements Driver
{
    /** @var array<string, Payment> */
    private array $payments = [];

    private int $counter = 0;

    public function name(): string
    {
        return 'mock';
    }

    public function collect(CollectRequest $request): Payment
    {
        $phone = (string) $request->phone;

        if ($request->phone->endsWith(MockScenarios::PHONE_TIMEOUT)) {
            throw new PaymentTimeoutException(sprintf('Mock timeout for %s: the provider never answered.', $phone));
        }

        $status = PaymentStatus::Succeeded;
        $failureReason = null;

        if ($request->phone->endsWith(MockScenarios::PHONE_INSUFFICIENT_FUNDS)) {
            $status = PaymentStatus::Failed;
            $failureReason = FailureReason::InsufficientFunds;
        } elseif ($request->phone->endsWith(MockScenarios::PHONE_CANCELLED)) {
            $status = PaymentStatus::Cancelled;
            $failureReason = FailureReason::CancelledByCustomer;
        }

        $payment = new Payment(
            id: $this->nextId(),
            driver: $this->name(),
            amount: $request->amount,
            currency: $request->currency,
            phone: $phone,
            status: $status,
            failureReason: $failureReason,
            description: $request->description,
            metadata: $request->metadata,
            reference: $request->reference,
            createdAt: new DateTimeImmutable(),
        );

        $this->payments[$payment->id] = $payment;

        return $payment;
    }

    public function status(string $transactionId): Payment
    {
        return $this->payments[$transactionId] ?? throw UnknownTransactionException::forId($transactionId);
    }

    private function nextId(): string
    {
        return sprintf('mock_%04d', ++$this->counter);
    }
}
