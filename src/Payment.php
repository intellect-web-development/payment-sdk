<?php

declare(strict_types=1);

namespace IWD\PaymentSdk;

use DateTimeImmutable;

/** Платёж Customer. Сумма — десятичная строка, чтобы не терять копейки на float */
final readonly class Payment
{
    public function __construct(
        public string $uuid,
        public int $userId,
        /** Непрозрачная ссылка продукта, переданная при создании (например, UUID счёта) */
        public string $reference,
        public string $amount,
        public PaymentStatus $status,
        public ConfirmationType $confirmationType,
        /** Токен подтверждения для виджета оплаты YooKassa */
        public ?string $confirmation,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
