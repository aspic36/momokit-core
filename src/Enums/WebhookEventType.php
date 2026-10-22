<?php

declare(strict_types=1);

namespace MomoKit\Core\Enums;

enum WebhookEventType: string
{
    case PaymentPending = 'payment.pending';
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case PaymentCancelled = 'payment.cancelled';
    case PaymentRefunded = 'payment.refunded';
    case Unknown = 'unknown';
}
