# edTechLive API

Laravel 13 API backend. The repository currently includes Sanctum, API v1 health/current-user/organization-list routes, and the initial organization/membership models and migrations.

## Requirements

- PHP 8.3+ with Laravel's required extensions and `pdo_pgsql` for the target database.
- Composer 2.x.
- PostgreSQL for normal local development.

## Setup

```sh
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Create a local PostgreSQL database named `edtechlive`, then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in the untracked `.env`. `.env.example` uses PostgreSQL by default. Do not point this project at production records during development.

## Migrations and tests

After confirming the local database target:

```sh
php artisan migrate
php artisan test
vendor/bin/pint --test
```

Tests use PHPUnit's in-memory SQLite configuration and do not require PostgreSQL. The migrations have not yet been run against PostgreSQL.

## Implemented API routes

- `GET /api/v1/health` — service/version health; it does not check database connectivity.
- `GET /api/v1/me` — Sanctum-authenticated current-user summary.
- `GET /api/v1/organizations` — authenticated user's active memberships only, paginated.

Registration, login, invitations, organization switching, role policies and the academic/LMS API are next. No API token is issued yet.
