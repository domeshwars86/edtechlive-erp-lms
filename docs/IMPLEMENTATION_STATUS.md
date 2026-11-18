# Implementation Status

**Updated:** 18 November 2026

## Current milestone

Phase 4 — initial web client/API connection. The website and role dashboard previews are in place. Browser account flows now use the Laravel API; dashboard activity, schedules, and records are still illustrative. PostgreSQL migrations and production email delivery remain unverified.

## Implemented

- Responsive, statically pre-rendered public website and all seven role dashboard previews.
- Laravel 13.43 API with Sanctum session/token authentication, throttled registration/login/recovery, signed email verification, password reset and membership-scoped organization reads.
- User, organization and membership models/migrations; organization registration creates an initial active membership transactionally.
- Web client API helper uses credentialed Fetch, Laravel CSRF cookie and `VITE_API_URL` configuration.
- Login session is restored from `/api/v1/me`; logout calls API; registration, forgot/reset password and resend verification invoke the corresponding API endpoints.
- Organization selection is loaded from the authenticated user's active memberships. Connected dashboard headers use the account and organization from API state; dashboard business data remains clearly labelled demonstration data.
- API and web setup/auth configuration is documented in `.env.example` files and `apps/api/README.md`.

## Current limitations

- API database migrations have not been applied to PostgreSQL; local PostgreSQL service status was not confirmed.
- Email delivery must be configured for verification and reset links.
- Contact enquiry is still browser-only and is not transmitted.
- Dashboard counts, schedules, tables, and all academic workflows remain sample data; no academic ERP or LMS endpoints exist yet.
- Invitations, organization switching API, detailed role policies, audit trail, MFA, and tenant-owned academic entities remain future work.
- Sitemap host must be replaced with the eventual production domain; legal drafts need review.
- npm audit has nine high severity findings through `picomatch@4.0.7`; do not apply its incompatible Vite 6.2.7 downgrade without an upstream-compatible fix.

## Verification

- `npm run typecheck` — passed.
- `npm run lint` — passed without warnings.
- `npm run build` — passed; prerendered 14 routes.
- Browser accessibility inspection confirmed login and registration screens render locally; no credentials or account creation were submitted. Full session verification needs a persistent local API database and mail transport.
- Laravel `php artisan test` — 11 passed / 51 assertions; Pint passed; 11 API v1 routes registered.
- No PostgreSQL migration or deployment has been performed.

## Next

1. Verify client auth flows against a persistent local development database and configure a safe local PostgreSQL service before migration validation.
2. Implement academic session and core master-entry APIs (classes, sections, subjects), policies, resources and tenant isolation tests.
3. Replace dashboard sample records with API-backed academic summaries once those endpoints exist.
4. Connect contact enquiries after its API endpoint and abuse controls are implemented.
5. Reassess npm audit advisories when compatible upstream dependency releases are available.
