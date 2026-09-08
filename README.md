# Housing Offers API

REST API на Laravel для асинхронного імпорту пропозицій житла, пошуку найдешевшої актуальної пропозиції та безпечного бронювання.

## Stack

- PHP 8.4+
- Laravel 12
- MySQL 8.4+
- Database queue
- PHPUnit 11

## Requirements

- PHP 8.4 або новіший PHP, сумісний із Laravel 12
- Composer 2.x
- MySQL 8.4+
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `ctype`, `tokenizer`, `xml`

Для локального MySQL можна використати Docker Compose. Він запускає лише базу даних, а Laravel і queue worker працюють локально через PHP 8.4.

## Installation

```bash
git clone <repository-url>
cd wtg-test
composer install
cp .env.example .env
php artisan key:generate
```

Запустіть MySQL:

```bash
docker compose up -d mysql
```

Параметри в `.env.example` відповідають цьому контейнеру. Якщо MySQL встановлений локально, змініть `DB_PORT` на `3306` і вкажіть власні credentials.

Після цього виконайте міграції та seeders:

```bash
php artisan migrate --seed
```

## Running the application

Запустіть HTTP-сервер:

```bash
php artisan serve
```

В окремому терміналі запустіть worker для асинхронного імпорту:

```bash
php artisan queue:work database --tries=1
```

## Tests

```bash
php artisan test
```

## API

Основні endpoints:

```text
POST /api/imports
GET  /api/imports/{import}
GET  /api/properties
POST /api/offers/{offer}/reservations
```

Приклади запитів і формат відповідей будуть наведені нижче після реалізації відповідних endpoint-ів.

## Design notes

- Імпорт створюється HTTP-запитом, а пропозиції обробляються через queued Job.
- Ідемпотентність імпорту забезпечується унікальним індексом на `supplier_id + external_import_id`.
- Пропозиції постачальника ідентифікуються унікальною комбінацією `supplier_id + external_id`.
- Ціна зберігається як ціле число у мінорних одиницях валюти.
- Захист від одночасного бронювання останньої одиниці буде реалізований транзакцією та `SELECT ... FOR UPDATE` для рядка пропозиції.

## Git

Проєкт розвивається окремими логічними комітами: інфраструктура, імпорт, пошук, бронювання, тести та документація.
