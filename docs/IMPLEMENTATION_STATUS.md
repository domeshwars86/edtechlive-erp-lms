# Implementation Status

**Updated:** 18 November 2026

## Current milestone

Phase 5 — academic ERP foundations. The public website and seven role dashboard layouts are in place. Registration, login, verification, recovery, organization membership, and the first academic master records use Laravel APIs. Dashboard metrics and non-master workflows remain illustrative. PostgreSQL and production email delivery remain unverified.

## Implemented

- Responsive, statically pre-rendered public website and all seven role dashboard previews.
- Laravel 13.43 API with Sanctum session/token authentication, throttled registration/login/recovery, signed email verification, password reset and membership-scoped organization reads.
- User, organization and membership models/migrations; organization registration creates an initial active membership transactionally.
- Web client API helper uses credentialed Fetch, Laravel CSRF cookie and `VITE_API_URL` configuration.
- Login session is restored from `/api/v1/me`; logout calls API; registration, forgot/reset password and resend verification invoke the corresponding API endpoints.
- Organization selection is loaded from the authenticated user's active memberships. Connected dashboard headers use the account and organization from API state; dashboard business data remains clearly labelled demonstration data.
- Tenant-scoped academic session, class, section, and subject migrations, models, and API routes are implemented. Active organization members can read; admin, management, and independent educator roles can create/update. Cross-tenant references are rejected.
- The admin dashboard includes create/list/archive flows for sessions, classes, sections, and subjects, backed by the Laravel API.
- API and web setup/auth configuration is documented in `.env.example` files and `apps/api/README.md`.

## Current limitations

- API database migrations have not been applied to PostgreSQL; local PostgreSQL service status was not confirmed.
- Email delivery must be configured for verification and reset links.
- Contact enquiry is still browser-only and is not transmitted.
- Dashboard counts, schedules, student/teacher management, attendance, timetable, and all LMS workflows remain sample data; academic master records are the first connected ERP slice.
- Invitations, organization switching API, detailed role policies, audit trail, MFA, and tenant-owned academic entities remain future work.
- Sitemap host must be replaced with the eventual production domain; legal drafts need review.
- npm audit has nine high severity findings through `picomatch@4.0.7`; do not apply its incompatible Vite 6.2.7 downgrade without an upstream-compatible fix.

## Verification

- `npm run typecheck` — passed after academic master UI integration.
- `npm run lint` — passed after academic master UI integration.
- `npm run build` — passed after academic master UI integration; prerendered 14 routes.
- Browser accessibility inspection confirmed login and registration screens render locally; no credentials or account creation were submitted. Full session verification needs a persistent local API database and mail transport.
- Full PHPUnit suite — 15 passed / 71 assertions using in-memory SQLite. The normal `php artisan test` runner cannot read its configured PHPUnit bootstrap in the current sandbox; PHPUnit passed with Composer autoload preloaded and safe in-memory DB environment settings.
- PHP syntax checks passed for application, migration, routes, and test files. The academic migration is applied to the ignored local SQLite development database; 14 organization routes are registered.
- `vendor/bin/pint --test` — passed after correcting import order. No PostgreSQL migration or deployment has been performed.

## Next

1. Verify client auth flows against a persistent local development database and configure a safe local PostgreSQL service before migration validation.
2. Implement teacher and student profiles, invitations, and role-specific roster APIs, with tenant isolation and dashboard screens.
3. Add timetable, attendance, and academic reporting APIs, then replace sample dashboard summaries.
4. Continue LMS entities and workflows: courses, lessons/materials, assignments, examinations, submissions, and results.
5. Connect contact enquiries after its API endpoint and abuse controls are implemented; configure mail delivery and add a local mail preview.
6. Reassess npm audit advisories when compatible upstream dependency releases are available.
