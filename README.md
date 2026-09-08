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

Request examples and response formats will be documented here as the endpoints are implemented.

## Design notes

- An import is created by the HTTP request, while offer processing runs in a queued Job.
- Import idempotency is enforced by a unique index on `supplier_id + external_import_id`.
- Supplier offers are uniquely identified by `supplier_id + external_id`.
- Prices are stored as integers in the smallest currency unit.
- Concurrent reservations of the last available unit will be protected by a database transaction and `SELECT ... FOR UPDATE` on the offer row.

## Git

The project is developed through separate logical commits for infrastructure, imports, search, reservations, tests, and documentation.
