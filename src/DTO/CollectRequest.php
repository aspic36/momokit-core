<?php

declare(strict_types=1);

namespace MomoKit\Core\DTO;

use MomoKit\Core\Exceptions\InvalidAmountException;
use MomoKit\Core\Support\PhoneNumber;

final readonly class CollectRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public PhoneNumber $phone,
        public ?string $description = null,
        public array $metadata = [],
        public ?string $reference = null,
    ) {
        if ($this->amount <= 0) {
            throw new InvalidAmountException('The amount must be a positive integer expressed in the smallest currency unit.');
        }
    }

    /**
     * Convenience factory that accepts a raw phone number string.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function make(
        int $amount,
        string $currency,
        string $phone,
        ?string $description = null,
        array $metadata = [],
        ?string $reference = null,
        ?string $defaultCountryCode = null,
    ): self {
        return new self(
            amount: $amount,
            currency: $currency,
            phone: PhoneNumber::from($phone, $defaultCountryCode),
            description: $description,
            metadata: $metadata,
            reference: $reference,
        );
    }
}
