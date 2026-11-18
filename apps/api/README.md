# edTechLive API

Laravel 13 API backend. The repository includes Sanctum authentication, email verification and password reset, plus the initial organization/membership models and migrations.

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

Set `FRONTEND_URL` to the SPA origin used in reset links, `CORS_ALLOWED_ORIGINS` to the allowed browser origins, and `SANCTUM_STATEFUL_DOMAINS` to the hostnames (including ports) of your first-party SPA. The example allows arbitrary local development ports only on `localhost` and `127.0.0.1`; use exact origins and hostnames for deployed environments. For cookie-based SPA auth, call `/sanctum/csrf-cookie`, then submit login with credentials and use the session cookie. For native clients, include a `device_name` during login and send the returned token as a Bearer token. Tokens expire after 30 days and can be revoked with logout.

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
- `POST /api/v1/auth/register` — creates a user, organization and initial admin membership, then sends a verification email. Responds with 202.
- `POST /api/v1/auth/login` — verified accounts only. Uses a first-party session by default or issues a 30-day token when `device_name` is supplied.
- `POST /api/v1/auth/logout` — revokes the current token or ends the browser session.
- `POST /api/v1/auth/email/verification-notification` and `GET /api/v1/auth/verify-email/{id}/{hash}` — throttled resend and signed, expiring verification.
- `POST /api/v1/auth/forgot-password` and `POST /api/v1/auth/reset-password` — generic reset-link response and token-based reset. Reset links go to `FRONTEND_URL/reset-password`.
- `GET /api/v1/me` — Sanctum-authenticated current-user summary.
- `GET /api/v1/organizations` and `GET /api/v1/organizations/{organization}` — verified users can access active organizations where they have an active membership; list is paginated.

Invitations, organization switching, role policies and the academic/LMS API remain future work. Registration/login and password recovery are implemented, but production email delivery, PostgreSQL migration, and production deployment are not configured here.
