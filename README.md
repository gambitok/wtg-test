# Housing Offers API

REST API built with Laravel for asynchronous housing offer imports, finding the cheapest current offer, and safely reserving available units.

Repository: [github.com/gambitok/wtg-test](https://github.com/gambitok/wtg-test)

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
- Docker Compose v2 (recommended)

Docker Compose runs the application, database queue worker, and MySQL. A local PHP setup can run the application and queue worker outside Docker while using the MySQL container.

## Reproduce the task from a clean checkout

```bash
git clone https://github.com/gambitok/wtg-test.git
cd wtg-test
cp .env.example .env
```

Start the full Docker environment:

```bash
docker compose build
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d
```

This starts the PHP application at `http://localhost:8000`, a separate queue worker, and MySQL. The application container connects to MySQL through the Compose service name `mysql`.

Run migrations and seed the two required suppliers:

```bash
docker compose exec app php artisan migrate --seed
```

`docker compose up` only starts the containers. It does not run Laravel migrations or seeders automatically. The command above creates the database tables and runs `SupplierSeeder`, which creates `supplier-a` and `supplier-b`. The application is ready at `http://127.0.0.1:8000` and the queue worker is already running in the `queue` container.

If the migrations have already been run and only the supplier records are missing, run the seeder directly:

```bash
docker compose exec app php artisan db:seed --class=SupplierSeeder
```

Without the supplier seed data, `POST /api/imports` returns `422 Unprocessable Content` with `The selected supplier is invalid.`

Check the running services and queue logs when needed:

```bash
docker compose ps
docker compose logs -f queue
```

Run the automated tests in the same environment:

```bash
docker compose exec -T app php artisan test
```

The test suite always uses the separate `housing_offers_testing` database and does not clear the development data in `housing_offers`. The testing database is created by `docker/mysql/init/01-create-testing-database.sql` when the MySQL volume is initialized on a clean checkout.

To reset the Docker environment and initialize both databases again:

```bash
docker compose down -v
docker compose up --build -d
docker compose exec app php artisan migrate --seed
```

The `down -v` command removes the local MySQL data volume.

### Local PHP option

Install the PHP dependencies and create the application key:

```bash
composer install
php artisan key:generate
```

If you run PHP locally instead, start only MySQL:

```bash
docker compose up -d mysql
```

The local `.env` values use port `3307`. If MySQL is installed locally, change `DB_PORT` to `3306` and provide the appropriate credentials.

When running PHP locally, use:

```bash
php artisan migrate --seed
```

If the database is already migrated, run the supplier seeder directly:

```bash
php artisan db:seed --class=SupplierSeeder
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

To reproduce the complete flow, submit an import with one offer:

```bash
curl -X POST http://127.0.0.1:8000/api/imports \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "supplier": "supplier-a",
    "external_import_id": "import-2026-09-08-demo-001",
    "sent_at": "2026-09-08T10:00:00Z",
    "offers": [
      {
        "external_id": "offer-a-demo-001",
        "property": {
          "code": "BCN-0001",
          "name": "Apartment near Sagrada Familia",
          "city": "Barcelona"
        },
        "check_in": "2026-10-10",
        "check_out": "2026-10-15",
        "max_guests": 4,
        "price": 72500,
        "currency": "EUR",
        "available_units": 2,
        "expires_at": "2026-12-31T23:59:59Z"
      }
    ]
  }'
```

Copy the returned import ID and request its status. The queue worker should change the status from `pending` or `processing` to `completed`:

```bash
curl http://127.0.0.1:8000/api/imports/{import-id} \
  -H 'Accept: application/json'
```

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

For a complete manual flow, first call the property search endpoint and copy `best_offer.id` from its response. Use that value as `{offer-id}`:

```bash
curl -X POST http://127.0.0.1:8000/api/offers/{offer-id}/reservations \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "client_reference": "web-order-demo-001",
    "customer_name": "John Smith",
    "customer_email": "john@example.com"
  }'
```

The response must have HTTP status `201`. A second request for the last remaining unit receives `409 Conflict`.

### Error responses

API routes return clean JSON error responses even when the `Accept` header is missing. Sending `Accept: application/json` is still recommended for API clients.

- `422 Unprocessable Content` — invalid request data, an unknown supplier, or a duplicate `client_reference`.
- `404 Not Found` — the requested import or offer does not exist.
- `405 Method Not Allowed` — the URL exists, but the HTTP method is not supported. For example, `GET /api/imports` is invalid because the import collection only accepts `POST`, and `POST /api/properties` is invalid because property search only accepts `GET`.
- `409 Conflict` — the offer is expired or has no available units when a reservation is attempted.

Example validation response:

```json
{
  "message": "The selected supplier is invalid.",
  "errors": {
    "supplier": [
      "The selected supplier is invalid."
    ]
  }
}
```

## Design notes

- An import is created by the HTTP request, while offer processing runs in a queued Job.
- Import idempotency is enforced by a unique index on `supplier_id + external_import_id`.
- Repeating the same import request returns the existing import and does not dispatch another processing Job. The database unique index is the final protection against duplicate requests arriving concurrently.
- Supplier offers are uniquely identified by `supplier_id + external_id`.
- The property search ranks eligible offers with a MySQL 8 window function and filters the cheapest row in SQL before pagination.
- Prices are stored as integers in the smallest currency unit.
- Concurrent reservations of the last available unit are protected by a database transaction and `SELECT ... FOR UPDATE` on the offer row. Requests lock the current offer, check its units and expiration, create the reservation, and decrement the units before the transaction commits. A second transaction therefore sees the updated unit count and receives `409 Conflict`.
