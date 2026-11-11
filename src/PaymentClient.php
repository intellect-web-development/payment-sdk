<?php

declare(strict_types=1);

namespace IWD\PaymentSdk;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент универсального сервиса Payment (платежи YooKassa).
 *
 * Токен — персональный JWT системы (выдаёт integration-registry): сервис берёт код системы из claim `sub`
 * и работает только с платежами этой системы и её магазином YooKassa.
 *
 * Ошибки транспорта и ответы 401/403/422/5xx пробрасываются исключениями Symfony HttpClient, обработка — на стороне
 * вызывающего (адаптер продукта). 404 превращается в null только у payment().
 */
final readonly class PaymentClient
{
    public const int REFERENCE_MAX_LENGTH = 64;
    public const int PAGE_SIZE_MAX = 100;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $token,
    ) {
    }

    /**
     * Создаёт платёж магазином YooKassa вашей системы и возвращает токен подтверждения для виджета.
     *
     * @param string $amount            десятичная строка не больше двух знаков после точки, например «150.50»
     * @param string $reference         ваша непрозрачная ссылка (куда зачислить деньги), до 64 символов
     * @param string $description       попадает в чек и уходит в налоговую: задаёт продукт, не пользователь
     * @param string $receiptEmail      куда отправить чек
     */
    public function createPayment(
        int $userId,
        string $reference,
        string $amount,
        string $description,
        string $receiptEmail,
    ): Payment {
        $this->assertReference($reference);

        return $this->mapPayment($this->request('POST', '/payments', [
            'json' => [
                'userId' => $userId,
                'reference' => $reference,
                'amount' => $amount,
                'description' => $description,
                'receiptEmail' => $receiptEmail,
            ],
        ]));
    }

    /** Платёж по UUID. null — платежа нет или он принадлежит другой системе */
    public function payment(string $uuid): ?Payment
    {
        try {
            return $this->mapPayment($this->request('GET', '/payments/' . rawurlencode($uuid)));
        } catch (ClientExceptionInterface $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Список платежей вашей системы. С `updatedSince` страницы идут от старых к новым (для сверки зачислений),
     * без него — от новых к старым.
     *
     * @param list<string>|null $references ссылки продукта; пустой массив даёт пустой результат без запроса
     */
    public function payments(
        ?array $references = null,
        ?PaymentStatus $status = null,
        ?DateTimeImmutable $updatedSince = null,
        int $page = 1,
        int $size = 20,
    ): PaymentList {
        if ([] === $references) {
            return new PaymentList(items: [], totalCount: 0);
        }
        if ($page < 1 || $size < 1 || $size > self::PAGE_SIZE_MAX) {
            throw new InvalidArgumentException(sprintf('page ≥ 1, size от 1 до %d.', self::PAGE_SIZE_MAX));
        }
        foreach ($references ?? [] as $reference) {
            $this->assertReference($reference);
            if (str_contains($reference, ',')) {
                throw new InvalidArgumentException('Ссылка в списке не может содержать запятую.');
            }
        }

        $query = array_filter([
            'references' => null === $references ? null : implode(',', $references),
            'status' => $status?->value,
            'updatedSince' => $updatedSince?->format(DateTimeInterface::ATOM),
            'page' => $page,
            'size' => $size,
        ], static fn (mixed $value): bool => null !== $value);

        $data = $this->request('GET', '/payments', ['query' => $query]);

        return new PaymentList(
            items: array_map($this->mapPayment(...), array_values($data['items'])),
            totalCount: (int) $data['totalCount'],
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = ['Authorization' => 'Bearer ' . $this->token];

        return $this->httpClient
            ->request($method, rtrim($this->baseUrl, '/') . '/api/sdk' . $path, $options)
            ->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapPayment(array $data): Payment
    {
        return new Payment(
            uuid: (string) $data['uuid'],
            userId: (int) $data['userId'],
            reference: (string) $data['reference'],
            amount: (string) $data['amount'],
            status: PaymentStatus::from((string) $data['status']),
            confirmationType: ConfirmationType::from((string) $data['confirmationType']),
            confirmation: isset($data['confirmation']) ? (string) $data['confirmation'] : null,
            createdAt: new DateTimeImmutable((string) $data['createdAt']),
            updatedAt: new DateTimeImmutable((string) $data['updatedAt']),
        );
    }

    private function assertReference(string $reference): void
    {
        if ('' === $reference || mb_strlen($reference) > self::REFERENCE_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Ссылка продукта: непустая строка до %d символов.', self::REFERENCE_MAX_LENGTH));
        }
    }
}
