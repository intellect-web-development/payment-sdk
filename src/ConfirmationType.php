<?php

declare(strict_types=1);

namespace IWD\PaymentSdk;

enum ConfirmationType: string
{
    case Redirect = 'redirect';
    case Embedded = 'embedded';
}
