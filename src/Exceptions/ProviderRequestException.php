<?php

declare(strict_types=1);

namespace MomoKit\Core\Exceptions;

final class ProviderRequestException extends MobileMoneyException
{
    public static function fromResponse(string $driver, int $statusCode, string $body): self
    {
        $snippet = trim($body);

        if (strlen($snippet) > 300) {
            $snippet = substr($snippet, 0, 300).'...';
        }

        return new self(sprintf(
            '%s request failed with HTTP %d%s',
            $driver,
            $statusCode,
            $snippet === '' ? '.' : ': '.$snippet,
        ));
    }

    public static function invalidResponse(string $driver, string $reason): self
    {
        return new self(sprintf('%s returned an invalid response: %s.', $driver, $reason));
    }

    public static function invalidPayload(string $driver, string $reason): self
    {
        return new self(sprintf('%s could not encode the request payload: %s.', $driver, $reason));
    }
}
