<?php

declare(strict_types=1);

namespace MomoKit\Core\Contracts;

use MomoKit\Core\DTO\CollectRequest;
use MomoKit\Core\DTO\Payment;
use MomoKit\Core\Exceptions\MobileMoneyException;

interface Driver
{
    /**
     * The driver name, e.g. "mock" or "fedapay".
     */
    public function name(): string;

    /**
     * Collect money from a customer's mobile money account.
     *
     * @throws MobileMoneyException
     */
    public function collect(CollectRequest $request): Payment;

    /**
     * Retrieve a payment by its transaction id.
     *
     * @throws MobileMoneyException
     */
    public function status(string $transactionId): Payment;
}
