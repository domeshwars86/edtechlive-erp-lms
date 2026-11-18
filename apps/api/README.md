# edTechLive API

Laravel 13 API backend. The repository includes Sanctum authentication, email verification and password reset, organization membership, and tenant-scoped academic session/class/section/subject APIs.

## Requirements

- PHP 8.3+ with Laravel's required extensions and `pdo_pgsql` for the target database.
- Composer 2.x.
- PostgreSQL for production-shaped validation; SQLite is available for isolated local development and automated tests.

## Setup

```sh
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Create a local PostgreSQL database named `edtechlive`, then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in the untracked `.env`. `.env.example` uses PostgreSQL by default. Do not point this project at production records during development. To use a local SQLite file, set `DB_CONNECTION=sqlite` and `DB_DATABASE=database/local-dev.sqlite` in the untracked `.env`; SQLite support must be enabled in PHP.

Set `FRONTEND_URL` to the SPA origin used in reset links, `CORS_ALLOWED_ORIGINS` to the allowed browser origins, and `SANCTUM_STATEFUL_DOMAINS` to the hostnames (including ports) of your first-party SPA. The example allows arbitrary local development ports only on `localhost` and `127.0.0.1`; use exact origins and hostnames for deployed environments. For cookie-based SPA auth, call `/sanctum/csrf-cookie`, then submit login with credentials and use the session cookie. For native clients, include a `device_name` during login and send the returned token as a Bearer token. Tokens expire after 30 days and can be revoked with logout.

## Migrations and tests

After confirming the local database target, migrate the development database:

```sh
php artisan migrate
php artisan test
vendor/bin/pint --test
```

Tests use in-memory SQLite and do not require PostgreSQL. The academic migration has been applied to the ignored local SQLite development database; migrations have not yet been validated against PostgreSQL.

## Implemented API routes

- `GET /api/v1/health` — service/version health; it does not check database connectivity.
- `POST /api/v1/auth/register` — creates a user, organization and initial admin membership, then sends a verification email. Responds with 202.
- `POST /api/v1/auth/login` — verified accounts only. Uses a first-party session by default or issues a 30-day token when `device_name` is supplied.
- `POST /api/v1/auth/logout` — revokes the current token or ends the browser session.
- `POST /api/v1/auth/email/verification-notification` and `GET /api/v1/auth/verify-email/{id}/{hash}` — throttled resend and signed, expiring verification.
- `POST /api/v1/auth/forgot-password` and `POST /api/v1/auth/reset-password` — generic reset-link response and token-based reset. Reset links go to `FRONTEND_URL/reset-password`.
- `GET /api/v1/me` — Sanctum-authenticated current-user summary.
- `GET /api/v1/organizations` and `GET /api/v1/organizations/{organization}` — verified users can access active organizations where they have an active membership; list is paginated.
- Under `/api/v1/organizations/{organization}`: `GET/POST/PATCH academic-sessions`, `GET/POST/PATCH classes`, nested `GET/POST classes/{class}/sections`, `PATCH sections/{section}`, and `GET/POST/PATCH subjects`. Read access requires active organization membership; writes require admin, management, or independent educator role. All records are organization-scoped.

Invitations, organization switching, people/rosters, attendance/schedules, and LMS APIs remain future work. Local `.env` uses the log mailer, so messages are recorded locally rather than delivered. Production email delivery, PostgreSQL migration, and deployment are not configured here.
