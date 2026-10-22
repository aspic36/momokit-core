<?php

declare(strict_types=1);

namespace MomoKit\Core\Drivers\FedaPay;

use InvalidArgumentException;

final readonly class FedaPayConfig
{
    public const SANDBOX_URL = 'https://sandbox-api.fedapay.com/v1';

    public const LIVE_URL = 'https://api.fedapay.com/v1';

    public function __construct(
        public string $secretKey,
        public string $environment = 'sandbox',
        public ?string $webhookSecret = null,
        public ?string $callbackUrl = null,
    ) {
        if (trim($this->secretKey) === '') {
            throw new InvalidArgumentException('The FedaPay secret key cannot be empty.');
        }

        if (! in_array($this->environment, ['sandbox', 'live'], true)) {
            throw new InvalidArgumentException('The FedaPay environment must be either "sandbox" or "live".');
        }
    }

    public function baseUrl(): string
    {
        return $this->environment === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
    }
}
