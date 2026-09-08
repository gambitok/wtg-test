# Housing Offers API

REST API built with Laravel for asynchronous housing offer imports, finding the cheapest current offer, and safely reserving available units.

## Stack

- PHP 8.4+
- Laravel 12
- MySQL 8.4+
- Database queue
- PHPUnit 11

## Requirements

- PHP 8.4 or another PHP version compatible with Laravel 12
- Composer 2.x
- MySQL 8.4+
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `ctype`, `tokenizer`, `xml`

Docker Compose can be used for the local MySQL instance. The Laravel application and queue worker run locally with PHP 8.4.

## Installation

```bash
git clone <repository-url>
cd wtg-test
composer install
cp .env.example .env
php artisan key:generate
```

Start the full Docker environment:

```bash
docker compose up --build -d
```

This starts the PHP application at `http://localhost:8000`, a separate queue worker, and MySQL. The application container connects to MySQL through the Compose service name `mysql`.

If you run PHP locally instead, start only MySQL:

```bash
docker compose up -d mysql
```

The local `.env` values use port `3307`. If MySQL is installed locally, change `DB_PORT` to `3306` and provide the appropriate credentials.

With the Docker environment, run migrations and seed the database inside the application container:

```bash
docker compose exec app php artisan migrate --seed
```

When running PHP locally, use:

```bash
php artisan migrate --seed
```

The seeder creates two suppliers: `supplier-a` and `supplier-b`.

Feature tests use the separate `housing_offers_testing` MySQL database. The database is created automatically by the MySQL initialization script when the Docker volume is created for the first time.

## Running the application locally

Start the HTTP server:

```bash
php artisan serve
```

In a separate terminal, start the queue worker for asynchronous imports:

```bash
php artisan queue:work database --tries=1
```

## Tests

```bash
php artisan test
```

## API

Main endpoints:

```text
POST /api/imports
GET  /api/imports/{import}
GET  /api/properties
POST /api/offers/{offer}/reservations
```

### Create an import

```bash
curl -X POST http://127.0.0.1:8000/api/imports \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "supplier": "supplier-a",
    "external_import_id": "import-2026-09-01-001",
    "sent_at": "2026-09-01T10:00:00Z",
    "offers": []
  }'
```

The endpoint validates the request, creates a `pending` import, dispatches a queued Job, and immediately returns `202 Accepted`. The Job processes properties and offers asynchronously.

### Get import status

```bash
curl http://127.0.0.1:8000/api/imports/{import} \
  -H 'Accept: application/json'
```

The status can be `pending`, `processing`, `completed`, or `failed`.

### Search properties

```bash
curl 'http://127.0.0.1:8000/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&page=1&per_page=15' \
  -H 'Accept: application/json'
```

The search filters offers by dates, guest capacity, availability, and expiration time. It returns one property row with the cheapest eligible offer for that property. The city filter is optional. `next`, `prev`, and `per_page` are returned for pagination.

### Create a reservation

```bash
curl -X POST http://127.0.0.1:8000/api/offers/{offer}/reservations \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "client_reference": "web-order-9f782b1c",
    "customer_name": "John Smith",
    "customer_email": "john@example.com"
  }'
```

The endpoint returns `201 Created` and stores the offer price and currency on the reservation. An unavailable or expired offer returns `409 Conflict`.

## Design notes

- An import is created by the HTTP request, while offer processing runs in a queued Job.
- Import idempotency is enforced by a unique index on `supplier_id + external_import_id`.
- Supplier offers are uniquely identified by `supplier_id + external_id`.
- The property search ranks eligible offers with a MySQL 8 window function and filters the cheapest row in SQL before pagination.
- Prices are stored as integers in the smallest currency unit.
- Concurrent reservations of the last available unit are protected by a database transaction and `SELECT ... FOR UPDATE` on the offer row. Requests lock the current offer, check its units and expiration, create the reservation, and decrement the units before the transaction commits. A second transaction therefore sees the updated unit count and receives `409 Conflict`.

## Git

The project is developed through separate logical commits for infrastructure, imports, search, reservations, tests, and documentation.
