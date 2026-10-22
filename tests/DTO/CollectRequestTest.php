<?php

declare(strict_types=1);

namespace MomoKit\Core\Tests\DTO;

use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\Exceptions\InvalidAmountException;
use PHPUnit\Framework\TestCase;

final class CollectRequestTest extends TestCase
{
    public function test_make_normalizes_the_phone_number(): void
    {
        $request = CollectRequest::make(5000, 'XOF', '+229 97 00 00 01');

        $this->assertSame('+22997000001', $request->phone->e164);
        $this->assertSame(5000, $request->amount);
        $this->assertSame('XOF', $request->currency);
    }

    public function test_make_accepts_a_default_country_code(): void
    {
        $request = CollectRequest::make(5000, 'XOF', '97 00 00 01', defaultCountryCode: '+229');

        $this->assertSame('+22997000001', $request->phone->e164);
    }

    public function test_amount_must_be_positive(): void
    {
        $this->expectException(InvalidAmountException::class);

        CollectRequest::make(0, 'XOF', '+22997000001');
    }

    public function test_amount_cannot_be_negative(): void
    {
        $this->expectException(InvalidAmountException::class);

        CollectRequest::make(-100, 'XOF', '+22997000001');
    }
}
