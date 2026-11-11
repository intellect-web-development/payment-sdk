# payment-sdk

HTTP-клиент и контракты шины универсального сервиса [Payment](../payment) (платежи YooKassa). Пакет `intellect-web-development/payment-sdk`, namespace `IWD\PaymentSdk\`.

Чеклист подключения продукта: корневой [`../payment/INTEGRATION.md`](../payment/INTEGRATION.md).

Сервис не знает счетов и начислений продукта. Продукт передаёт непрозрачную ссылку `reference` (до 64 символов, например UUID счёта) и получает её обратно в платеже и в списке. Зачисление денег — на стороне продукта, по событию `paymentSucceeded`.

```php
$client = new PaymentClient($httpClient, baseUrl: 'http://backend-payment.localhost', token: $personalJwt);

// Платёж магазином YooKassa вашей системы. Сумма — десятичная строка, не float
$payment = $client->createPayment(
    userId: 42,
    reference: $accountUuid,
    amount: '150.50',
    description: 'Пополнение баланса',   // уходит в чек: задаёт продукт, не пользователь
    receiptEmail: 'payer@example.com',
);
$payment->confirmation;   // токен для виджета YooKassa
$payment->status;         // PaymentStatus::Pending

// Чтение. null — платежа нет или он принадлежит другой системе
$client->payment($payment->uuid);

// Список. С updatedSince страницы идут от старых к новым: так сверяют зачисления
$client->payments(references: [$accountUuid], status: PaymentStatus::Succeeded, page: 1, size: 20);   // PaymentList: items, totalCount
```

Токен — персональный JWT системы (аудитория `payment`, scopes `payment:payments:{read,write}`), его выдаёт integration-registry. Сервис работает только с данными системы из claim `sub`.

Ошибки: ответы 4xx/5xx и сбои транспорта пробрасываются исключениями Symfony HttpClient как есть. 404 превращается в `null` только у `payment()`. Слишком длинная `reference` и неверные `page`/`size` дают `InvalidArgumentException` до запроса. `422` у `createPayment` — не подключён магазин YooKassa или YooKassa не создала платёж.

## События

Сервис публикует тонкие адресные события (routing key = код вашей системы) в exchange `payment.events`: `payment.event.paymentSucceeded`, `payment.event.paymentCanceled`, `payment.event.paymentRefunded`. Поля: `paymentUuid`, `integrationSystemCode`. Классы — `IWD\PaymentSdk\Message\Event\*`. Доставка at-least-once: обработчик идемпотентен. Данные платежа берите через `payment()`.

Очередь `{slug}.payment` объявляет продукт: см. `examples/symfony/messenger.yaml`.

## HTTP-контракт

Все пути под `/api/sdk/`, заголовок `Authorization: Bearer <JWT>`. Тела — JSON, даты — ISO 8601 (ATOM).

| Метод клиента | HTTP | Тело / параметры | Ответ |
|---|---|---|---|
| `createPayment` | `POST /payments` | `userId`, `reference`, `amount`, `description`, `receiptEmail` | `Payment`, 201 |
| `payment` | `GET /payments/{uuid}` | | `Payment`, 404 — нет такого платежа |
| `payments` | `GET /payments` | `references` (через запятую), `status`, `updatedSince`, `page`, `size` | `{"items": [Payment], "totalCount": n}` |

## Примеры wiring

`examples/symfony/`: `services.yaml` (DI клиента), `messenger.yaml` (очередь событий), `PaymentGateway.php` (адаптер продукта).

## Тесты

```shell
composer install && vendor/bin/phpunit
```
