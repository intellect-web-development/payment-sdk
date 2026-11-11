<?php

declare(strict_types=1);

namespace IWD\PaymentSdk;

final readonly class PaymentList
{
    /** @param list<Payment> $items */
    public function __construct(
        public array $items,
        public int $totalCount,
    ) {
    }
}
