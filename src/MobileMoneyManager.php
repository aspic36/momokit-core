<?php

declare(strict_types=1);

namespace MomoKit\Core;

use MomoKit\Core\Contracts\Driver;
use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\DTO\Payment;
use MomoKit\Core\Exceptions\UnknownDriverException;
use MomoKit\Core\Testing\MobileMoneyFake;

final class MobileMoneyManager
{
    /** @var array<string, Driver> */
    private array $drivers = [];

    public function __construct(
        private ?string $defaultDriver = null,
    ) {
    }

    public function register(Driver $driver): static
    {
        $this->drivers[$driver->name()] = $driver;
        $this->defaultDriver ??= $driver->name();

        return $this;
    }

    /**
     * @throws UnknownDriverException
     */
    public function driver(?string $name = null): Driver
    {
        $name ??= $this->defaultDriver;

        if ($name === null || ! isset($this->drivers[$name])) {
            throw UnknownDriverException::forName($name);
        }

        return $this->drivers[$name];
    }

    public function defaultDriver(): ?string
    {
        return $this->defaultDriver;
    }

    /**
     * @return array<string, Driver>
     */
    public function drivers(): array
    {
        return $this->drivers;
    }

    /**
     * Replace every registered driver with an in-memory fake.
     *
     * @param  string  ...$drivers  Extra driver names to fake, even without credentials.
     */
    public function fake(string ...$drivers): MobileMoneyFake
    {
        return MobileMoneyFake::install($this, ...$drivers);
    }

    /**
     * @throws UnknownDriverException
     */
    public function collect(CollectRequest $request, ?string $driver = null): Payment
    {
        return $this->driver($driver)->collect($request);
    }
}
