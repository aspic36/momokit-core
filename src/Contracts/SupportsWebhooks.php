<?php

declare(strict_types=1);

namespace MomoKit\Core\Contracts;

use MomoKit\Core\DTO\WebhookEvent;
use MomoKit\Core\Exceptions\InvalidWebhookSignatureException;

interface SupportsWebhooks
{
    /**
     * Verify the provider signature and normalize the webhook payload.
     *
     * @param  string  $payload  The raw request body, exactly as received.
     * @param  string  $signatureHeader  The signature header sent by the provider.
     *
     * @throws InvalidWebhookSignatureException
     */
    public function parseWebhook(string $payload, string $signatureHeader): WebhookEvent;
}
