<?php

declare(strict_types=1);

namespace MomoKit\Core\DTO;

use MomoKit\Core\Enums\PaymentStatus;
use MomoKit\Core\Enums\WebhookEventType;

final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload  The decoded provider payload, kept as-is.
     */
    public function __construct(
        public string $driver,
        public string $name,
        public WebhookEventType $type,
        public ?string $transactionId = null,
        public ?PaymentStatus $status = null,
        public array $payload = [],
    ) {
    }
}
