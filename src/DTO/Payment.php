<?php

declare(strict_types=1);

namespace MomoKit\Core\DTO;

use DateTimeImmutable;
use MomoKit\Core\Enums\FailureReason;
use MomoKit\Core\Enums\PaymentStatus;

final readonly class Payment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $driver,
        public int $amount,
        public string $currency,
        public string $phone,
        public PaymentStatus $status,
        public ?FailureReason $failureReason = null,
        public ?string $description = null,
        public array $metadata = [],
        public ?string $reference = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }
}
