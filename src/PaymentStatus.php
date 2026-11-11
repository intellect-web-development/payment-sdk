<?php

declare(strict_types=1);

namespace IWD\PaymentSdk;

/** Статус платежа. pending → succeeded; pending или succeeded → canceled или refunded */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
}
