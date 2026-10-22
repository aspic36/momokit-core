<?php

declare(strict_types=1);

namespace MomoKit\Core\Testing;

use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\DTO\Payment;
use MomoKit\Core\Enums\PaymentStatus;
use MomoKit\Core\Exceptions\UnknownDriverException;
use MomoKit\Core\Exceptions\UnknownTransactionException;
use MomoKit\Core\MobileMoneyManager;

/**
 * Drop-in replacement for every driver, for tests and local demos.
 *
 * Payments stay pending until you decide otherwise with succeed(), fail()
 * or cancel(); every collect and status check is recorded for assertions.
 */
final class MobileMoneyFake
{
    /** @var array<string, FakeDriver> */
    private array $drivers = [];

    private function __construct(private readonly MobileMoneyManager $manager)
    {
    }

    /**
     * Extra driver names let you fake a driver that has no credentials
     * configured, e.g. fake('fedapay').
     */
    public static function install(MobileMoneyManager $manager, string ...$extraDrivers): self
    {
        $names = array_keys($manager->drivers());

        if (($default = $manager->defaultDriver()) !== null) {
            $names[] = $default;
        }

        foreach ($extraDrivers as $name) {
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            $names[] = 'mock';
        }

        $fake = new self($manager);

        foreach (array_values(array_unique($names)) as $name) {
            $driver = new FakeDriver($name);
            $fake->drivers[$name] = $driver;
            $manager->register($driver);
        }

        return $fake;
    }

    /**
     * @throws UnknownDriverException
     */
    public function driver(?string $name = null): FakeDriver
    {
        $name ??= $this->manager->defaultDriver();

        if ($name === null || ! isset($this->drivers[$name])) {
            throw UnknownDriverException::forName($name);
        }

        return $this->drivers[$name];
    }

    /**
     * All recorded collect requests, across every fake driver.
     *
     * @return list<CollectRequest>
     */
    public function collected(?string $driver = null): array
    {
        if ($driver !== null) {
            return $this->driver($driver)->collects();
        }

        $collects = [];

        foreach ($this->drivers as $fake) {
            foreach ($fake->collects() as $request) {
                $collects[] = $request;
            }
        }

        return $collects;
    }

    public function lastCollect(?string $driver = null): ?CollectRequest
    {
        $collects = $this->collected($driver);

        return $collects === [] ? null : $collects[count($collects) - 1];
    }

    /**
     * @return list<string>
     */
    public function statusChecks(?string $driver = null): array
    {
        if ($driver !== null) {
            return $this->driver($driver)->statusChecks();
        }

        $checks = [];

        foreach ($this->drivers as $fake) {
            foreach ($fake->statusChecks() as $transactionId) {
                $checks[] = $transactionId;
            }
        }

        return $checks;
    }

    /**
     * @throws UnknownTransactionException
     */
    public function payment(string $transactionId, ?string $driver = null): Payment
    {
        if ($driver !== null) {
            return $this->driver($driver)->payment($transactionId);
        }

        foreach ($this->drivers as $fake) {
            if ($fake->hasPayment($transactionId)) {
                return $fake->payment($transactionId);
            }
        }

        throw UnknownTransactionException::forId($transactionId);
    }

    /**
     * @throws UnknownTransactionException
     */
    public function markAs(string $transactionId, PaymentStatus $status, ?string $driver = null): self
    {
        if ($driver !== null) {
            $this->driver($driver)->markAs($transactionId, $status);

            return $this;
        }

        foreach ($this->drivers as $fake) {
            if ($fake->hasPayment($transactionId)) {
                $fake->markAs($transactionId, $status);

                return $this;
            }
        }

        throw UnknownTransactionException::forId($transactionId);
    }

    public function succeed(string $transactionId, ?string $driver = null): self
    {
        return $this->markAs($transactionId, PaymentStatus::Succeeded, $driver);
    }

    public function fail(string $transactionId, ?string $driver = null): self
    {
        return $this->markAs($transactionId, PaymentStatus::Failed, $driver);
    }

    public function cancel(string $transactionId, ?string $driver = null): self
    {
        return $this->markAs($transactionId, PaymentStatus::Cancelled, $driver);
    }

    /**
     * @param  (callable(CollectRequest): bool)|null  $callback
     */
    public function assertCollected(?callable $callback = null): void
    {
        $collects = $this->collected();

        if ($collects === []) {
            throw new FakeAssertionFailed('Expected at least one collect request, but none was recorded.');
        }

        if ($callback === null) {
            return;
        }

        foreach ($collects as $request) {
            if ($callback($request) === true) {
                return;
            }
        }

        throw new FakeAssertionFailed('No recorded collect request matched the given callback.');
    }

    public function assertCollectedTimes(int $times): void
    {
        $count = count($this->collected());

        if ($count !== $times) {
            throw new FakeAssertionFailed(sprintf(
                'Expected %d collect request(s), %d recorded.',
                $times,
                $count,
            ));
        }
    }

    public function assertNothingCollected(): void
    {
        $count = count($this->collected());

        if ($count !== 0) {
            throw new FakeAssertionFailed(sprintf('Expected no collect request, %d recorded.', $count));
        }
    }

    public function assertStatusChecked(string $transactionId): void
    {
        if (! in_array($transactionId, $this->statusChecks(), true)) {
            throw new FakeAssertionFailed(sprintf(
                'Expected the status of payment [%s] to be checked, but it was not.',
                $transactionId,
            ));
        }
    }
}
