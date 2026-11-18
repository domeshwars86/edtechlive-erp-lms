# Implementation Status

**Updated:** 18 November 2026

## Current milestone

Phase 3 — Laravel backend and tenancy foundation. Phases 1 (public website) and 2 (dashboard frontends) have working frontend previews and passed initial gates. API, Sanctum and tenant schema foundation is in place; database migrations are not applied.

## Implemented in this milestone

- Vite/React/TypeScript app scaffold and responsive shared visual system.
- Public homepage, About, Features, Courses, Institution, Teacher, Student, Pricing and Contact pages.
- Login, registration, forgot/reset password, role choice and organization selection previews.
- Draft Privacy, Terms, Cookie, Acceptable Use and Refund pages; clear legal-review disclaimer.
- Responsive mobile navigation, route fallback, FAQ disclosure, course search/level filter and client-side enquiry validation.
- Demonstration content/pricing is labelled; no user data is transmitted by forms.
- Basic document metadata, robots and sitemap placeholders.
- Public-route static prerendering with per-route title and description metadata.
- Initial responsive dashboards for all seven roles, including role switching, sidebar navigation, sample metrics/lists, search and mobile sidebar behavior.
- Laravel 13.43.0 API app created under `apps/api`, with Sanctum 4.5.0 installed.
- Versioned API health, authenticated current-user and membership-scoped organization-list endpoints.
- Organization and organization-membership enums, models and migrations; tests cover health, authentication, password omission and membership isolation.
- Local `.env` app key generated and kept ignored by git; `.env.example` defaults to PostgreSQL.
- Product and engineering baseline documentation under `docs/`.

## Current limitations

- Contact and authentication do not call an API or persist information.
- Course data is illustrative only; detail/enrollment and educator profiles are not implemented.
- Database migrations are written but not applied; no local PostgreSQL service was confirmed, and Laravel's default SQLite migration step was intentionally not used.
- Registration, login, invitations, organization switching, detailed policies and domain modules are not implemented yet.
- Integrations, payments, AI and mobile apps are not started.
- Sitemap host must be replaced with the eventual production domain.
- External font import requires a network connection; fallback sans-serif fonts are specified.
- Legal content is draft language and requires qualified review.

## Checks

Public checks: `npm run typecheck`, `npm run lint` and static prerender production build passed on Vite 8.3.8. Browser preview validated public routes/interactions and seven dashboard roles at target widths. Static HTML smoke checks confirmed route content and metadata. API checks: Laravel route list showed the three versioned routes; `php artisan test --compact` passed 5 tests / 17 assertions after generating a local-only app key; Pint formatting check passed. npm audit reports 9 high severity findings through picomatch 4.0.7; its incompatible suggested Vite 6.2.7 downgrade was not applied. Initial work is committed as `da205d0`. No PostgreSQL migration or deployment has been performed.

## Next

1. Complete organization registration, login/session/token handling, email verification and membership authorization.
2. Confirm a safe local PostgreSQL service/database, then apply and test migrations there.
3. Investigate npm's nine high severity advisories when registry access is available.
4. Keep demo records clearly marked and replace sitemap domain when known.
