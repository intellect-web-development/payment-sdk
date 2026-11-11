<?php

declare(strict_types=1);

namespace App\Common\Payment;

use DateTimeImmutable;
use IWD\PaymentSdk\Payment;
use IWD\PaymentSdk\PaymentClient;
use IWD\PaymentSdk\PaymentList;
use IWD\PaymentSdk\PaymentStatus;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Пример адаптера продукта: единственная точка связи с Payment.
 * Скопировать в продукт при opt-in; домен PaymentClient не импортирует.
 *
 * Любая ошибка сервиса превращается в PaymentUnavailableHttpException (в продукте — 502): платёж не создаётся, деньги не теряются.
 */
final readonly class PaymentGateway
{
    public function __construct(
        private PaymentClient $paymentClient,
        private LoggerInterface $logger,
    ) {
    }

    /** @param string $amount десятичная строка, например «150.50» */
    public function create(int $userId, string $reference, string $amount, string $description, string $receiptEmail): Payment
    {
        return $this->call(
            operation: 'create',
            userId: $userId,
            request: fn (): Payment => $this->paymentClient->createPayment($userId, $reference, $amount, $description, $receiptEmail),
        );
    }

    public function find(string $uuid): ?Payment
    {
        return $this->call(
            operation: 'find',
            userId: null,
            request: fn (): ?Payment => $this->paymentClient->payment($uuid),
        );
    }

    /** @param list<string>|null $references */
    public function search(?array $references, ?PaymentStatus $status, ?DateTimeImmutable $updatedSince, int $page, int $size): PaymentList
    {
        return $this->call(
            operation: 'search',
            userId: null,
            request: fn (): PaymentList => $this->paymentClient->payments($references, $status, $updatedSince, $page, $size),
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $request
     *
     * @return T
     */
    private function call(string $operation, ?int $userId, callable $request): mixed
    {
        try {
            return $request();
        } catch (HttpExceptionInterface $exception) {
            $this->logger->error('Payment request failed', [
                'operation' => $operation,
                'userId' => $userId,
                'status' => $exception->getResponse()->getStatusCode(),
            ]);
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Payment transport failed', [
                'operation' => $operation,
                'userId' => $userId,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
