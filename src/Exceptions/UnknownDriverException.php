<?php

declare(strict_types=1);

namespace MomoKit\Core\Exceptions;

final class UnknownDriverException extends MobileMoneyException
{
    public static function forName(?string $name): self
    {
        if ($name === null || $name === '') {
            return new self('No default mobile money driver is configured.');
        }

        return new self(sprintf('Mobile money driver "%s" is not registered.', $name));
    }
}
