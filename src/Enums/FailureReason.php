<?php

declare(strict_types=1);

namespace MomoKit\Core\Enums;

enum FailureReason: string
{
    case InsufficientFunds = 'insufficient_funds';
    case CancelledByCustomer = 'cancelled_by_customer';
    case ProviderError = 'provider_error';
}
