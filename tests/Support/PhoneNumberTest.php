<?php

declare(strict_types=1);

namespace MomoKit\Core\Tests\Support;

use MomoKit\Core\Exceptions\InvalidPhoneNumberException;
use MomoKit\Core\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    public function test_it_normalizes_international_numbers(): void
    {
        $this->assertSame('+22997000001', PhoneNumber::from('+229 97 00 00 01')->e164);
    }

    public function test_it_normalizes_the_00_international_prefix(): void
    {
        $this->assertSame('+22997000001', PhoneNumber::from('00229 97 00 00 01')->e164);
    }

    public function test_it_normalizes_local_numbers_with_a_default_country_code(): void
    {
        $this->assertSame('+22997000001', PhoneNumber::from('97 00 00 01', '+229')->e164);
    }

    public function test_it_keeps_numbers_that_already_carry_a_country_code(): void
    {
        $this->assertSame('+2250700000000', PhoneNumber::from('225 07 00 00 00 00')->e164);
    }

    public function test_it_rejects_numbers_that_are_too_short(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneNumber::from('123');
    }

    public function test_it_rejects_input_without_digits(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneNumber::from('not a number');
    }

    public function test_it_matches_scenario_suffixes(): void
    {
        $this->assertTrue(PhoneNumber::from('+229 97 00 00 01')->endsWith('97000001'));
        $this->assertFalse(PhoneNumber::from('+229 97 00 00 02')->endsWith('97000001'));
    }

    public function test_it_casts_to_string(): void
    {
        $this->assertSame('+22997000001', (string) PhoneNumber::from('+22997000001'));
    }
}
