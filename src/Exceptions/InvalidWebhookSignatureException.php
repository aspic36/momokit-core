<?php

declare(strict_types=1);

namespace MomoKit\Core\Exceptions;

final class InvalidWebhookSignatureException extends MobileMoneyException
{
    public static function reason(string $reason): self
    {
        return new self('Invalid webhook signature: '.$reason.'.');
    }
}
