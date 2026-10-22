<?php

declare(strict_types=1);

namespace MomoKit\Core\Tests;

use MomoKit\Core\Drivers\Mock\MockDriver;
use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\Exceptions\UnknownDriverException;
use MomoKit\Core\MobileMoneyManager;
use PHPUnit\Framework\TestCase;

final class MobileMoneyManagerTest extends TestCase
{
    public function test_the_first_registered_driver_becomes_the_default(): void
    {
        $manager = (new MobileMoneyManager())->register(new MockDriver());

        $this->assertSame('mock', $manager->defaultDriver());
        $this->assertSame('mock', $manager->driver()->name());
    }

    public function test_an_explicit_default_driver_wins(): void
    {
        $manager = (new MobileMoneyManager('mock'))->register(new MockDriver());

        $this->assertSame('mock', $manager->driver()->name());
    }

    public function test_it_throws_when_no_driver_is_available(): void
    {
        $this->expectException(UnknownDriverException::class);

        (new MobileMoneyManager())->driver();
    }

    public function test_it_throws_for_unknown_driver_names(): void
    {
        $manager = (new MobileMoneyManager())->register(new MockDriver());

        $this->expectException(UnknownDriverException::class);

        $manager->driver('fedapay');
    }

    public function test_collect_uses_the_default_driver(): void
    {
        $manager = (new MobileMoneyManager())->register(new MockDriver());

        $payment = $manager->collect(
            CollectRequest::make(5000, 'XOF', '+22997000001'),
        );

        $this->assertTrue($payment->succeeded());
    }

    public function test_collect_can_target_a_specific_driver(): void
    {
        $manager = (new MobileMoneyManager())->register(new MockDriver());

        $payment = $manager->collect(
            CollectRequest::make(5000, 'XOF', '+22997000002'),
            driver: 'mock',
        );

        $this->assertFalse($payment->succeeded());
    }
}
