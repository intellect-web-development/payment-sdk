<?php

declare(strict_types=1);

namespace IWD\PaymentSdk\Message\Event;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Событие Payment: платёж отменён. Адресовано одному Customer (routing key = его код).
 * Тонкое: только идентификатор платежа. Данные платежа получатель берёт по PaymentClient::payment().
 * Доставка at-least-once: обработчик обязан быть идемпотентным.
 */
#[AsMessage(
    transport: 'payment-events-transport',
    serializedTypeName: 'payment.event.paymentCanceled',
)]
readonly class PaymentCanceledMessage
{
    public function __construct(
        public string $paymentUuid,
        public string $integrationSystemCode,
    ) {
    }
}
