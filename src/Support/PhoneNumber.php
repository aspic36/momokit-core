<?php

declare(strict_types=1);

namespace MomoKit\Core\Support;

use MomoKit\Core\Exceptions\InvalidPhoneNumberException;

final readonly class PhoneNumber
{
    private function __construct(
        public string $e164,
    ) {
    }

    /**
     * Normalize a raw phone number ("+229 97 00 00 01", "0022997000001", "97000001")
     * into E.164 format ("+22997000001").
     *
     * When the number is local (no "+" and no "00" prefix), a default country
     * code such as "+229" is prepended.
     */
    public static function from(string $raw, ?string $defaultCountryCode = null): self
    {
        $trimmed = trim($raw);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (str_starts_with($trimmed, '+')) {
            $international = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $international = substr($digits, 2);
        } elseif ($defaultCountryCode !== null && strlen($digits) <= 9) {
            $international = ltrim($defaultCountryCode, '+0').$digits;
        } else {
            $international = $digits;
        }

        if (strlen($international) < 8 || strlen($international) > 15) {
            throw new InvalidPhoneNumberException(sprintf('"%s" is not a valid phone number.', $raw));
        }

        return new self('+'.$international);
    }

    public function endsWith(string $suffix): bool
    {
        return str_ends_with($this->e164, $suffix);
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
