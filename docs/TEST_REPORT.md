# Test Report

**Updated:** 18 November 2026

## Frontend checks

- `npm run typecheck` — passed.
- `npm run lint` — passed without warnings after splitting the auth hook from its provider.
- `npm run build` — passed; Vite built client and SSR bundles and prerendered 14 public routes.
- Local browser accessibility inspection confirmed the connected login and registration screens render with the expected fields and API status note. No credentials were entered and no account was created through the UI.
- Full browser-session verification was not performed because a persistent development database and mail transport are not configured. The first failed local browser launch was due to sandbox network restrictions; the approved local Vite server then loaded successfully and was stopped after review.

## Backend/API checks

- Baseline API test suite before the academic master milestone — **11 passed, 51 assertions**, using PHPUnit's in-memory SQLite configuration.
- Coverage includes registration and duplicate handling, signed verification, unverified login denial, bearer token issuance/logout, first-party session login/logout, password reset, membership-scoped organization access and current-user data.
- `vendor/bin/pint --test` — passed on the baseline API milestone.
- `php artisan route:list --path=api/v1` — 11 versioned routes registered.
- PostgreSQL migrations have not been run; no safe local PostgreSQL target has been confirmed.

## Academic master milestone (18 November 2026)

- Full PHPUnit regression suite — **15 passed / 71 assertions** using in-memory SQLite. Coverage includes academic session/class/section/subject access, current-session switching, cross-tenant denial, and write-role denial along with the existing authentication and organization tests.
- PHP syntax checks passed for application, migration, route, and test files.
- `php artisan migrate --force` applied the academic master migration to the ignored local SQLite development database; `php artisan route:list --path=api/v1/organizations` shows 14 routes.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed after connecting the admin master-entry screen; the build prerendered 14 routes.
- In this sandbox `is_readable('vendor/autoload.php')` returns false despite successful direct PHP inclusion, so PHPUnit was run with Composer autoload preloaded, without XML bootstrap, and with explicit in-memory SQLite settings. No local development account data was used in tests.
- `vendor/bin/pint --test` — passed after correcting import order; write mode was unnecessary.

## People roster milestone (18 November 2026)

- Full PHPUnit suite — **19 passed / 95 assertions** using in-memory SQLite. Added coverage for student and teacher creation/search, duplicate identifiers, cross-tenant access/placement, and student-role denial.
- `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered routes), PHP syntax checks, and `vendor/bin/pint --test` passed after adding the roster APIs and admin forms.
- `php artisan migrate --force` applied the ignored local SQLite people-profile migration; 20 organization routes are registered.
- Production email, invitation/account linking, and PostgreSQL migration validation remain unconfigured.

## Attendance and timetable milestone (18 November 2026)

- Full PHPUnit suite — **29 passed / 133 assertions** using in-memory SQLite. Coverage includes date-bounded attendance, batch update/upsert, class/session enrollment checks, tenant isolation, staff-only access, schedule session alignment, HTTPS links, and overlap conflicts.
- TypeScript, ESLint, and Pint passed for the admin attendance and timetable screens/APIs.
- `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered routes), PHP syntax checks, and `vendor/bin/pint --test` passed after the attendance/timetable integration.
- Attendance and timetable migrations were applied to the ignored local SQLite development database; `route:list` shows 25 organization-related routes.
- Local HTTP smoke check returned 200 for the admin workspace and the same-origin proxied Laravel health endpoint. No authenticated browser workflow was submitted.

## Existing QA and known dependency issue

Browser preview checks from 18 November 2026 previously reviewed public routes, course filtering and all seven dashboard preview roles across representative viewport widths. This milestone's browser check focused on the new account forms and was not a full responsive regression pass.

`npm audit` identified nine high findings in the Vite/TypeScript-ESLint dependency tree via `picomatch@4.0.7`. The registry reported 4.0.7 as the latest picomatch release and recommended an incompatible Vite 6.2.7 downgrade; that downgrade was not applied. Recheck when compatible upstream fixes are available.
