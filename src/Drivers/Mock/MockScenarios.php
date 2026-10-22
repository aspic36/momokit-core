<?php

declare(strict_types=1);

namespace MomoKit\Core\Drivers\Mock;

/**
 * Magic phone number suffixes used to trigger scenarios, whatever the
 * country code: "+229 97 00 00 01" and "97000001" both hit PHONE_SUCCESS.
 */
final class MockScenarios
{
    public const PHONE_SUCCESS = '97000001';

    public const PHONE_INSUFFICIENT_FUNDS = '97000002';

    public const PHONE_TIMEOUT = '97000003';

    public const PHONE_CANCELLED = '97000004';
}
