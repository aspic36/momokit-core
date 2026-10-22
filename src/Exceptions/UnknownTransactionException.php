<?php

declare(strict_types=1);

namespace MomoKit\Core\Exceptions;

final class UnknownTransactionException extends MobileMoneyException
{
    public static function forId(string $transactionId): self
    {
        return new self(sprintf('Transaction "%s" is unknown to this driver.', $transactionId));
    }
}
