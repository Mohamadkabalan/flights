# Flight API

A production-quality REST API for managing flights composed of nested **legs** and **segments**, built with **Laravel 12** (targeting **PHP 8.5**). Updates are processed **asynchronously** via a **Redis-backed queue** managed by **Laravel Horizon**, with full **idempotency** and **concurrency** safety.

The API exposes exactly three endpoints:

| Method        | Path                      | Purpose                           | Success |
| ------------- | ------------------------- | --------------------------------- | ------- |
| `POST`        | `/api/flights`            | Create a flight (legs + segments) | `201`   |
| `PUT`/`POST`  | `/api/flights/{flightId}` | Update a flight (asynchronous)    | `204`   |
| `GET`         | `/api/flights/{flightId}` | Fetch a flight's legs/segments    | `200`   |

All endpoints require a valid `Api-Key` header.

---

## Table of contents

- [Requirements](#requirements)
- [Quick start with Docker (zero to running)](#quick-start-with-docker-zero-to-running)
- [Setup with a local PHP toolchain](#setup-with-a-local-php-toolchain-option-b)
- [Running with Sail](#running-with-sail-option-b-alternative)
- [Migrations](#migrations)
- [Starting Horizon](#starting-horizon)
- [Running tests](#running-tests)
- [API examples](#api-examples)
- [Authentication](#authentication)
- [Idempotency behaviour](#idempotency-behaviour)
- [Update matching strategy](#update-matching-strategy)
- [Architecture](#architecture)

---

## Requirements

You only need **one** of the following toolchains:

**Option A — Docker only (recommended for a clean clone).** Just Docker Desktop / Docker Engine with the Compose plugin. No PHP, Composer, MySQL, or Redis on your host — everything runs in containers.

**Option B — Local PHP toolchain.** PHP 8.5 (8.2+ works; 8.5 is the production target), Composer 2, and access to MySQL/PostgreSQL + Redis (Sail can still provide these via Docker).

---

## Quick start with Docker (zero to running)

This is the fastest path on a fresh clone. With only Docker installed:

```bash
# 1. Clone the repository
git clone https://github.com/Mohamadkabalan/flights.git
cd flights

# 2. Use the ready-made Docker environment file
#    (hosts already point at the `mysql` and `redis` service names)
cp .env.docker .env

# 3. Build the images and start the whole stack
docker compose up --build
```

That single `docker compose up --build` does everything:

- builds a self-contained PHP image (installs PHP extensions + Composer + runs `composer install` during the build — nothing needed on your host),
- starts **MySQL**, **Redis**, the **app**, and a dedicated **Horizon** worker,
- waits for MySQL to be ready, generates the `APP_KEY`, and **runs migrations automatically** on first boot (see `docker/entrypoint.sh`),
- serves the API at **http://localhost** (the container's port 8000 is mapped to host port 80 — change `APP_PORT` in `.env` if 80 is taken).

To run it in the background, add `-d`:

```bash
docker compose up --build -d      # detached
docker compose logs -f app        # follow the app logs
docker compose logs -f horizon    # follow the queue worker
docker compose down               # stop everything
docker compose down -v            # stop and delete the DB/Redis volumes (full reset)
```

### Verifying it works

```bash
# Create a flight (replace the Api-Key with your .env value)
curl -X POST http://localhost/api/flights \
  -H "Api-Key: 123456789" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"legs":[{"segments":[{"origin":"BCN","destination":"LON","departure":"2026-06-09T06:45:00","arrival":"2026-06-09T10:55:00","cabinClass":"Y","airline":"UA","flightNumber":"101"}]}]}'
# -> {"flightId":"...."}
```

The Horizon dashboard is available at **http://localhost/horizon**.

### Running artisan / composer inside the container

```bash
docker compose exec app php artisan migrate:status
docker compose exec app php artisan tinker
docker compose exec app composer install
```

### Troubleshooting

- **Port 80 already in use:** set `APP_PORT=8080` in `.env` and use `http://localhost:8080`.
- **First build is slow:** the image installs PHP extensions and runs `composer install` once; subsequent starts reuse the cached layer. (There is no committed `composer.lock`, so the first install resolves the latest compatible dependency versions.)
- **App starts before MySQL is ready:** the stack uses healthchecks and an entrypoint wait-loop, so this should not happen — but if migrations error on a very slow machine, just re-run `docker compose up` (migrations are idempotent).
- **Reset everything:** `docker compose down -v` removes the database and Redis volumes for a clean slate.

---

## Setup with a local PHP toolchain (Option B)

If you prefer to run PHP on your host:

```bash
# 1. Install PHP dependencies
composer install

# 2. Create your environment file
cp .env.example .env

# 3. Generate the application key
php artisan key:generate

# 4. Set your API key (any strong secret) in .env:
#    API_KEY=123456789
#    Also point DB_HOST / REDIS_HOST at your services (127.0.0.1 if local).
```

The most important environment values:

| Variable           | Meaning                                                        |
| ------------------ | -------------------------------------------------------------- |
| `API_KEY`          | Shared secret required in the `Api-Key` header for every call  |
| `DB_CONNECTION`    | `mysql` (default) or `pgsql`                                   |
| `QUEUE_CONNECTION` | `redis` (default). Set to `sync` to process updates inline     |
| `CACHE_STORE`      | `redis` (default) — required for the idempotency Redis lock    |
| `REDIS_HOST`       | `redis` in Docker, or `127.0.0.1` locally                      |

---

## Running with Sail (Option B alternative)

[Laravel Sail](https://laravel.com/docs/sail) is Laravel's own Docker wrapper. It becomes available **after** `composer install` (it is a dev dependency). If you used the local toolchain above, you can then publish and use Sail:

```bash
# (once) install the Sail docker-compose if you want to use Sail specifically
php artisan sail:install

# start / stop
./vendor/bin/sail up -d
./vendor/bin/sail down
```

> **Note:** the project's own `docker-compose.yml` (used in the Quick start above) is self-contained and does **not** require Sail. Use the Quick start for a clean clone; use Sail only if you specifically prefer its tooling after a local `composer install`.

> **Tip:** alias for brevity — `alias sail='./vendor/bin/sail'` — then `sail artisan ...`, `sail composer ...`.

---

## Migrations

With the Docker Quick start, migrations run **automatically on first boot**. To run them manually:

```bash
# Inside Docker
docker compose exec app php artisan migrate

# Local toolchain
php artisan migrate

# Fresh rebuild (drops everything and re-migrates)
php artisan migrate:fresh
```

This creates `flights`, `flight_legs`, `flight_segments`, and `idempotency_keys`, plus the framework's queue/cache support tables.

---

## Starting Horizon

Update jobs run on a Redis queue supervised by Horizon.

- **Docker Quick start:** a dedicated `horizon` worker container is already running — nothing to do. Watch it with `docker compose logs -f horizon`.
- **Local toolchain:** run the worker yourself:

```bash
php artisan horizon
```

The Horizon dashboard is served at **`/horizon`** (http://localhost/horizon in Docker). Access is governed by the `viewHorizon` gate in `app/Providers/HorizonServiceProvider.php` (open in `local`, locked down elsewhere — adapt to your auth model for production).

> If you prefer not to run a worker during development, set `QUEUE_CONNECTION=sync` in `.env`. Update jobs then execute inline within the request lifecycle (the API still returns `204`).

## Running tests

The test suite runs hermetically against an in-memory SQLite database, the `array` cache, and the `sync` queue (configured in `phpunit.xml`), so no external services are needed.

```bash
php artisan test
# or
./vendor/bin/phpunit
# or, under Sail:
./vendor/bin/sail test
```

### What is covered

| #  | Scenario                                               | Test |
| -- | ------------------------------------------------------ | ---- |
| 1  | Create a flight with nested legs and segments          | `FlightCreateAndGetTest` |
| 2  | Get a flight by UUID (exact response shape)            | `FlightCreateAndGetTest` |
| 3  | Update one leg asynchronously (partial update)         | `FlightUpdateTest` |
| 4  | Update all legs asynchronously (full update)           | `FlightUpdateTest` |
| 5  | Api-Key protection (valid key passes)                  | `FlightCreateAndGetTest` |
| 6  | Missing (`401`) / invalid (`403`) Api-Key              | `FlightCreateAndGetTest` |
| 7  | Validation errors (empty legs, no segments, bad dates) | `FlightCreateAndGetTest` |
| 8  | Idempotency-Key prevents duplicate processing          | `FlightUpdateTest` |
| 9  | Concurrent duplicate requests with the same key        | `FlightUpdateTest` |
| 10 | Transaction rollback on failure                        | `TransactionRollbackTest` |

Plus a focused unit test for the idempotency request hashing (`RequestHasherTest`).

---

## API examples

All requests require the `Api-Key` header. Update requests additionally require an `Idempotency-Key` header.

### Create a flight

```bash
curl -X POST http://localhost/api/flights \
  -H "Api-Key: 123456789" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "legs": [
      {
        "segments": [
          {
            "origin": "BCN", "destination": "LON",
            "departure": "2026-06-09T06:45:00", "arrival": "2026-06-09T10:55:00",
            "cabinClass": "Y", "airline": "UA", "flightNumber": "101"
          },
          {
            "origin": "LON", "destination": "JFK",
            "departure": "2026-06-09T11:55:00", "arrival": "2026-06-09T14:55:00",
            "cabinClass": "Y", "airline": "UA", "flightNumber": "102"
          }
        ]
      }
    ]
  }'
```

Response — `201 Created`:

```json
{ "flightId": "9b2e7c1a-..." }
```

### Get a flight

```bash
curl http://localhost/api/flights/9b2e7c1a-... \
  -H "Api-Key: 123456789" \
  -H "Accept: application/json"
```

Response — `200 OK`:

```json
{
  "flightId": "9b2e7c1a-...",
  "legs": [
    {
      "segments": [
        {
          "origin": "BCN", "destination": "LON",
          "departure": "2026-06-09T06:45:00", "arrival": "2026-06-09T10:55:00",
          "cabinClass": "Y", "airline": "UA", "flightNumber": "101"
        }
      ]
    }
  ]
}
```

### Update a flight (asynchronous)

```bash
curl -X PUT http://localhost/api/flights/9b2e7c1a-... \
  -H "Api-Key: 123456789" \
  -H "Idempotency-Key: 7f3c9a52-unique-per-update" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "legs": [
      {
        "segments": [
          {
            "origin": "BCN", "destination": "LON",
            "departure": "2026-06-09T06:40:00", "arrival": "2026-06-09T10:50:00",
            "cabinClass": "Y", "airline": "UA", "flightNumber": "101"
          },
          {
            "origin": "LON", "destination": "JFK",
            "departure": "2026-06-09T11:55:00", "arrival": "2026-06-09T14:55:00",
            "cabinClass": "Y", "airline": "UA", "flightNumber": "102"
          }
        ]
      }
    ]
  }'
```

Response — `204 No Content`. The endpoint validates, registers the idempotency key, dispatches a queue job, and returns immediately. The database is updated by the **queued job**.

---

## Authentication

Every endpoint is guarded by the `EnsureApiKeyIsValid` middleware:

- The client must send the secret in the `Api-Key` header.
- The secret is read from `config/auth-api.php` → `API_KEY` env (never hard-coded).
- **Missing** header → `401 Unauthorized`.
- **Present but wrong** → `403 Forbidden`.
- Comparison uses a constant-time check (`hash_equals`) to avoid timing attacks.
- If no server-side key is configured, the middleware **fails closed** (rejects all).

---

## Idempotency behaviour

Updates must be safe to retry. Every update requires a client-supplied **`Idempotency-Key`** header, and the system guarantees a given key is processed **exactly once**.

How it works:

1. **Atomic registration.** On each update, the request body is hashed (canonically — see below) and an `idempotency_keys` row is **inserted atomically**. A `UNIQUE(key, operation)` database constraint arbitrates concurrency: under a race, only one insert wins. There is **no "check-then-insert"** (which would have a time-of-check/time-of-use race).
2. **First request (ACCEPTED).** The insert succeeds → the update job is dispatched → `204`.
3. **Duplicate / retry (DUPLICATE).** The insert hits the unique constraint and the stored request hash **matches** → no new job is dispatched → still `204`. Observably identical to the first call.
4. **Conflict (CONFLICT).** The key already exists but the request hash **differs** (same key, different body) → `422 Unprocessable Entity`. Reusing a key for a different request is a client error.

**Request hashing** is canonical: object keys are recursively sorted (so cosmetic JSON key-ordering differences don't matter), while leg/segment **order is preserved** (because order is meaningful). The target flight UUID is folded into the hash, so the same key cannot be reused across different flights.

**Job-side safety** (in `UpdateFlightJob`), layered deliberately:

- A short-lived **Redis lock** (per idempotency key) admits one worker at a time.
- A **database row claim** with a lease transitions the key `pending → processing` only if it is still claimable; this is the durable "process once" guarantee and survives a Redis flush. A dead worker's lease expires and can be reclaimed.
- The update runs inside a **database transaction** with a **pessimistic row lock** (`SELECT ... FOR UPDATE`) on the flight, so concurrent updates to the same flight serialize and partial writes roll back atomically.

On success the key is marked `completed` (storing the `204`); on terminal failure it is marked `failed` (never left stuck in `processing`).

---

## Update matching strategy

Update payloads carry **no leg or segment IDs**, so matching incoming data to existing rows is **positional and deterministic**:

- **Legs** are matched by order: the *i*-th incoming leg updates the existing leg with `leg_order = i + 1`.
- **Segments** are matched by order within their leg: the *j*-th incoming segment updates the existing segment with `segment_order = j + 1`.

Rules:

- **Partial update.** If the payload contains **fewer legs** than exist, only the overlapping leading legs are updated; trailing existing legs are **left untouched**. (A single incoming leg updates **only leg #1**.)
- **In-place update.** Where positions align, segments are updated in place (the row keeps its identity; only its data changes).
- **Structural reconciliation.** If an incoming leg has a **different number of segments** than the existing leg, that leg's segments are reconciled to mirror the submitted structure: surplus existing segments are **deleted**, missing ones are **created**. This is the "existing structure requires replacement" case.
- **No leg growth.** An incoming leg with no positional counterpart is **ignored** — the contract is to synchronise known legs, not to grow the flight.

This logic lives in `app/Repositories/FlightRepository.php` (the source of truth) and runs entirely within the update transaction.

---

## Architecture

Clean separation of responsibilities:

```
HTTP request
   |
   |-- EnsureApiKeyIsValid (middleware)        -> Api-Key auth (401/403)
   |
   |-- Store/UpdateFlightRequest (form request)-> validation + typed accessors
   |
   |-- FlightController (thin)                 -> delegates, shapes response
   |     |-- FlightCreationService             -> transactional create
   |     \-- FlightUpdateDispatcher            -> idempotency + job dispatch
   |            \-- IdempotencyManager         -> register / claim / lock / finalize
   |
   |-- UpdateFlightJob (queued)                -> the actual transactional update
   |     \-- FlightRepository                  -> locking + positional matching
   |
   \-- FlightResource                          -> response formatting
```

- **Controllers** are thin — no business logic or persistence.
- **Form Requests** own all validation; a shared base avoids duplication.
- **Services** hold business logic (create, dispatch).
- **The repository** owns locking and the positional matching algorithm.
- **The job** orchestrates the asynchronous, transactional update.
- **The `IdempotencyManager`** isolates all idempotency logic in one reusable place.
- **API Resources** format responses (camelCase fields, naive ISO datetimes, no `data` wrapper).
- **Database transactions** protect every multi-table write.

### Database schema

| Table              | Purpose                                                       |
| ------------------ | ------------------------------------------------------------- |
| `flights`          | Aggregate root; numeric `id` + public `uuid`                  |
| `flight_legs`      | Belongs to a flight; `leg_order` preserves position           |
| `flight_segments`  | Belongs to a leg; `segment_order` preserves position; details |
| `idempotency_keys` | Deduplication, concurrency control, and replay for updates    |

Unique constraints (`flights.uuid`, `(flight_id, leg_order)`, `(flight_leg_id, segment_order)`, `(key, operation)`) enforce structural integrity and idempotency at the database level.
