<?php

declare(strict_types=1);

namespace MomoKit\Core\Tests\Drivers;

use MomoKit\Core\Drivers\Mock\MockDriver;
use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\Enums\FailureReason;
use MomoKit\Core\Enums\PaymentStatus;
use MomoKit\Core\Exceptions\PaymentTimeoutException;
use MomoKit\Core\Exceptions\UnknownTransactionException;
use PHPUnit\Framework\TestCase;

final class MockDriverTest extends TestCase
{
    private MockDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new MockDriver();
    }

    public function test_success_number_returns_a_succeeded_payment(): void
    {
        $payment = $this->driver->collect(
            CollectRequest::make(5000, 'XOF', '+229 97 00 00 01', 'Commande #42'),
        );

        $this->assertTrue($payment->succeeded());
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNull($payment->failureReason);
        $this->assertSame(5000, $payment->amount);
        $this->assertSame('XOF', $payment->currency);
        $this->assertSame('+22997000001', $payment->phone);
        $this->assertSame('Commande #42', $payment->description);
        $this->assertSame('mock', $payment->driver);
        $this->assertStringStartsWith('mock_', $payment->id);
    }

    public function test_insufficient_funds_number_returns_a_failed_payment(): void
    {
        $payment = $this->driver->collect(
            CollectRequest::make(5000, 'XOF', '+22997000002'),
        );

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertFalse($payment->succeeded());
        $this->assertSame(FailureReason::InsufficientFunds, $payment->failureReason);
    }

    public function test_cancelled_number_returns_a_cancelled_payment(): void
    {
        $payment = $this->driver->collect(
            CollectRequest::make(5000, 'XOF', '+22997000004'),
        );

        $this->assertSame(PaymentStatus::Cancelled, $payment->status);
        $this->assertSame(FailureReason::CancelledByCustomer, $payment->failureReason);
    }

    public function test_timeout_number_throws(): void
    {
        $this->expectException(PaymentTimeoutException::class);

        $this->driver->collect(CollectRequest::make(5000, 'XOF', '+22997000003'));
    }

    public function test_any_other_number_succeeds(): void
    {
        $payment = $this->driver->collect(
            CollectRequest::make(1000, 'XOF', '+22997123456'),
        );

        $this->assertTrue($payment->succeeded());
    }

    public function test_scenarios_match_whatever_the_country_code(): void
    {
        $payment = $this->driver->collect(
            CollectRequest::make(5000, 'XOF', '97000001'),
        );

        $this->assertTrue($payment->succeeded());
    }

    public function test_payment_ids_are_unique(): void
    {
        $first = $this->driver->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));
        $second = $this->driver->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_status_returns_a_stored_payment(): void
    {
        $payment = $this->driver->collect(CollectRequest::make(5000, 'XOF', '+22997000001'));

        $stored = $this->driver->status($payment->id);

        $this->assertSame($payment->id, $stored->id);
        $this->assertSame($payment->status, $stored->status);
    }

    public function test_status_throws_for_an_unknown_transaction(): void
    {
        $this->expectException(UnknownTransactionException::class);

        $this->driver->status('mock_9999');
    }

    public function test_metadata_is_carried_through(): void
    {
        $payment = $this->driver->collect(
            CollectRequest::make(5000, 'XOF', '+22997000001', metadata: ['order_id' => 42]),
        );

        $this->assertSame(['order_id' => 42], $payment->metadata);
    }
}
