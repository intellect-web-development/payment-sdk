<?php

declare(strict_types=1);

namespace IWD\PaymentSdk\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use IWD\PaymentSdk\ConfirmationType;
use IWD\PaymentSdk\PaymentClient;
use IWD\PaymentSdk\PaymentStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

final class PaymentClientTest extends TestCase
{
    private const string PAYMENT = '{"uuid":"019c4000-0000-7000-8000-000000000001","userId":42,"reference":"acc-1","amount":"150.50","status":"pending","confirmationType":"embedded","confirmation":"token-1","createdAt":"2026-11-11T10:00:00+00:00","updatedAt":"2026-11-11T10:00:00+00:00"}';

    /** @var array{method: string, url: string, headers: list<string>, body: string} */
    private array $captured = [];

    public function testCreatePaymentPostsFieldsAndMapsPayment(): void
    {
        $payment = $this->client(self::PAYMENT, 201)->createPayment(
            userId: 42,
            reference: 'acc-1',
            amount: '150.50',
            description: 'Пополнение',
            receiptEmail: 'payer@example.com',
        );

        self::assertSame('POST', $this->captured['method']);
        self::assertSame('http://payment.test/api/sdk/payments', $this->captured['url']);
        self::assertSame(
            ['userId' => 42, 'reference' => 'acc-1', 'amount' => '150.50', 'description' => 'Пополнение', 'receiptEmail' => 'payer@example.com'],
            $this->body(),
        );
        self::assertContains('Authorization: Bearer jwt-token', $this->captured['headers']);
        self::assertSame('019c4000-0000-7000-8000-000000000001', $payment->uuid);
        self::assertSame(42, $payment->userId);
        self::assertSame('150.50', $payment->amount);
        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertSame(ConfirmationType::Embedded, $payment->confirmationType);
        self::assertSame('token-1', $payment->confirmation);
        self::assertSame('2026-11-11T10:00:00+00:00', $payment->createdAt->format(DATE_ATOM));
    }

    public function testCreatePaymentRejectsLongReferenceBeforeRequest(): void
    {
        $client = $this->client(self::PAYMENT, 201);

        $this->expectException(InvalidArgumentException::class);
        try {
            $client->createPayment(userId: 1, reference: str_repeat('a', 65), amount: '1', description: 'd', receiptEmail: 'a@b.c');
        } finally {
            self::assertSame([], $this->captured);
        }
    }

    public function testPaymentReadsByUuid(): void
    {
        $payment = $this->client(self::PAYMENT)->payment('019c4000-0000-7000-8000-000000000001');

        self::assertSame('GET', $this->captured['method']);
        self::assertSame('http://payment.test/api/sdk/payments/019c4000-0000-7000-8000-000000000001', $this->captured['url']);
        self::assertSame('acc-1', $payment?->reference);
    }

    public function testPaymentReturnsNullOnNotFound(): void
    {
        self::assertNull($this->client('{"messages":["Payment not found."]}', 404)->payment('019c4000-0000-7000-8000-000000000009'));
    }

    public function testPaymentPropagatesOtherClientErrors(): void
    {
        $this->expectException(ClientExceptionInterface::class);

        $this->client('{}', 403)->payment('019c4000-0000-7000-8000-000000000001');
    }

    public function testPaymentsSendsFiltersAndMapsList(): void
    {
        $list = $this->client('{"items":[' . self::PAYMENT . '],"totalCount":7}')->payments(
            references: ['acc-1', 'acc-2'],
            status: PaymentStatus::Succeeded,
            updatedSince: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
            page: 2,
            size: 50,
        );

        self::assertSame('GET', $this->captured['method']);
        parse_str((string) parse_url($this->captured['url'], PHP_URL_QUERY), $query);
        self::assertSame('acc-1,acc-2', $query['references']);
        self::assertSame('succeeded', $query['status']);
        self::assertSame('2026-11-01T00:00:00+00:00', $query['updatedSince']);
        self::assertSame('2', $query['page']);
        self::assertSame('50', $query['size']);
        self::assertSame(7, $list->totalCount);
        self::assertCount(1, $list->items);
        self::assertSame('acc-1', $list->items[0]->reference);
    }

    public function testPaymentsWithEmptyReferencesMakesNoRequest(): void
    {
        $list = $this->client('{}')->payments(references: []);

        self::assertSame([], $this->captured);
        self::assertSame([], $list->items);
        self::assertSame(0, $list->totalCount);
    }

    public function testPaymentsRejectsReferenceWithComma(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client('{}')->payments(references: ['a,b']);
    }

    public function testPaymentsRejectsInvalidPaging(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client('{}')->payments(size: 101);
    }

    public function testServerErrorsArePropagated(): void
    {
        $this->expectException(ServerExceptionInterface::class);

        $this->client('{}', 502)->createPayment(userId: 1, reference: 'r', amount: '1', description: 'd', receiptEmail: 'a@b.c');
    }

    private function client(string $body, int $status = 200): PaymentClient
    {
        $this->captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($body, $status): MockResponse {
            $this->captured = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => (string) ($options['body'] ?? ''),
            ];

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
        });

        return new PaymentClient($httpClient, 'http://payment.test', 'jwt-token');
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->captured['body'], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
