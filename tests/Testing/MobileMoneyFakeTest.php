<?php

declare(strict_types=1);

namespace MomoKit\Core\Tests\Testing;

use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\Drivers\Mock\MockDriver;
use MomoKit\Core\Enums\FailureReason;
use MomoKit\Core\Enums\PaymentStatus;
use MomoKit\Core\Exceptions\UnknownDriverException;
use MomoKit\Core\Exceptions\UnknownTransactionException;
use MomoKit\Core\MobileMoneyManager;
use MomoKit\Core\Testing\FakeAssertionFailed;
use MomoKit\Core\Testing\FakeDriver;
use MomoKit\Core\Testing\MobileMoneyFake;
use PHPUnit\Framework\TestCase;

final class MobileMoneyFakeTest extends TestCase
{
    private MobileMoneyManager $manager;

    private MobileMoneyFake $fake;

    protected function setUp(): void
    {
        $this->manager = (new MobileMoneyManager())->register(new MockDriver());
        $this->fake = $this->manager->fake();
    }

    public function test_it_replaces_registered_drivers(): void
    {
        $driver = $this->manager->driver();

        $this->assertInstanceOf(FakeDriver::class, $driver);
        $this->assertSame('mock', $driver->name());
        $this->assertSame($driver, $this->fake->driver());
    }

    public function test_collect_records_the_request_and_returns_a_pending_payment(): void
    {
        $payment = $this->manager->collect(
            CollectRequest::make(5000, 'XOF', '+229 97 00 00 01', 'Commande #42'),
        );

        $this->assertSame('mock_fake_0001', $payment->id);
        $this->assertSame('mock', $payment->driver);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('Commande #42', $payment->description);
        $this->assertSame('https://checkout.momokit.test/mock/mock_fake_0001', $payment->checkoutUrl);

        $collects = $this->fake->collected();

        $this->assertCount(1, $collects);
        $this->assertSame(5000, $collects[0]->amount);
        $this->assertSame('+22997000001', (string) $collects[0]->phone);
        $this->assertSame($collects[0], $this->fake->lastCollect());
    }

    public function test_status_returns_the_stored_payment_and_records_the_check(): void
    {
        $payment = $this->manager->driver()->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));

        $checked = $this->manager->driver()->status($payment->id);

        $this->assertSame(PaymentStatus::Pending, $checked->status);
        $this->assertSame([$payment->id], $this->fake->statusChecks());
        $this->fake->assertStatusChecked($payment->id);
    }

    public function test_succeed_marks_the_payment_as_succeeded(): void
    {
        $payment = $this->manager->driver()->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));

        $this->fake->succeed($payment->id);

        $updated = $this->manager->driver()->status($payment->id);

        $this->assertSame(PaymentStatus::Succeeded, $updated->status);
        $this->assertNull($updated->failureReason);
        $this->assertSame(1000, $updated->amount);
    }

    public function test_fail_and_cancel_set_a_failure_reason(): void
    {
        $first = $this->manager->driver()->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));
        $second = $this->manager->driver()->collect(CollectRequest::make(2000, 'XOF', '+22997000001'));

        $this->fake->fail($first->id);
        $this->fake->cancel($second->id);

        $this->assertSame(FailureReason::ProviderError, $this->fake->payment($first->id)->failureReason);
        $this->assertSame(FailureReason::CancelledByCustomer, $this->fake->payment($second->id)->failureReason);
        $this->assertSame(PaymentStatus::Failed, $this->fake->payment($first->id)->status);
        $this->assertSame(PaymentStatus::Cancelled, $this->fake->payment($second->id)->status);
    }

    public function test_extra_drivers_can_be_faked_without_credentials(): void
    {
        $fake = $this->manager->fake('fedapay');

        $payment = $this->manager->driver('fedapay')->collect(CollectRequest::make(2500, 'XOF', '+22997000001'));

        $this->assertSame('fedapay_fake_0001', $payment->id);
        $this->assertSame(1, count($fake->collected('fedapay')));
        $this->assertSame(0, count($fake->collected('mock')));

        $fake->succeed($payment->id);

        $this->assertSame(PaymentStatus::Succeeded, $this->manager->driver('fedapay')->status($payment->id)->status);
    }

    public function test_an_unknown_driver_is_rejected_by_the_fake(): void
    {
        $this->expectException(UnknownDriverException::class);
        $this->addToAssertionCount(1);

        $this->fake->driver('kkiapay');
    }

    public function test_an_unknown_transaction_is_rejected(): void
    {
        $this->expectException(UnknownTransactionException::class);
        $this->addToAssertionCount(1);

        $this->fake->succeed('missing');
    }

    public function test_payments_are_shared_across_fake_drivers(): void
    {
        $fake = $this->manager->fake('fedapay');

        $mockPayment = $this->manager->driver('mock')->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));
        $fedaPayment = $this->manager->driver('fedapay')->collect(CollectRequest::make(2000, 'XOF', '+22997000001'));

        $this->assertSame($mockPayment->id, $fake->payment($mockPayment->id)->id);
        $this->assertSame($fedaPayment->id, $fake->payment($fedaPayment->id)->id);

        $fake->succeed($fedaPayment->id);

        $this->assertSame(PaymentStatus::Succeeded, $this->manager->driver('fedapay')->status($fedaPayment->id)->status);
        $this->assertSame(PaymentStatus::Pending, $this->manager->driver('mock')->status($mockPayment->id)->status);
    }

    public function test_assert_collected_passes_with_and_without_a_callback(): void
    {
        $this->fake->assertNothingCollected();

        $this->manager->driver()->collect(CollectRequest::make(5000, 'XOF', '+22997000001'));

        $this->fake->assertCollected();
        $this->fake->assertCollected(static fn (CollectRequest $request): bool => $request->amount === 5000);
        $this->fake->assertCollectedTimes(1);

        $this->addToAssertionCount(3);
    }

    public function test_assert_nothing_collected_fails_when_a_collect_was_recorded(): void
    {
        $this->manager->driver()->collect(CollectRequest::make(5000, 'XOF', '+22997000001'));

        $this->expectException(FakeAssertionFailed::class);
        $this->expectExceptionMessage('Expected no collect request, 1 recorded.');

        $this->fake->assertNothingCollected();
    }

    public function test_assert_collected_fails_on_an_unmatched_callback(): void
    {
        $this->manager->driver()->collect(CollectRequest::make(5000, 'XOF', '+22997000001'));

        $this->expectException(FakeAssertionFailed::class);
        $this->expectExceptionMessage('No recorded collect request matched the given callback.');

        $this->fake->assertCollected(static fn (CollectRequest $request): bool => $request->amount === 9999);
    }

    public function test_assert_collected_times_reports_the_recorded_count(): void
    {
        $this->manager->driver()->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));

        $this->expectException(FakeAssertionFailed::class);
        $this->expectExceptionMessage('Expected 2 collect request(s), 1 recorded.');

        $this->fake->assertCollectedTimes(2);
    }

    public function test_assert_status_checked_fails_when_the_status_was_never_checked(): void
    {
        $payment = $this->manager->driver()->collect(CollectRequest::make(1000, 'XOF', '+22997000001'));

        $this->expectException(FakeAssertionFailed::class);
        $this->expectExceptionMessage(sprintf('Expected the status of payment [%s] to be checked', $payment->id));

        $this->fake->assertStatusChecked($payment->id);
    }
}
