# Test Report

**Updated:** 18 November 2026

## Automated checks

On 18 November 2026, with Node 20.19, Vite 8.3.8 and `@vitejs/plugin-react` 6.3.0, `npm run typecheck`, `npm run lint` and `npm run build` passed. The production build generated 14 route-specific public HTML pages.

`npm audit --json` identified nine high findings in the Vite/TypeScript-ESLint dependency tree via `picomatch@4.0.7`. Vite was upgraded to 8.3.8 with `@vitejs/plugin-react` 6.3.0 and Node 20.19; the findings remain because npm reports 4.0.7 as the latest picomatch release and recommends an incompatible Vite 6.2.7 downgrade. No forced downgrade was applied; monitor upstream remediation.

## Browser/viewport checks

Browser preview checks performed on 18 November 2026:

- Public routes (home, about, features, courses, three solutions, pricing, contact, auth flows, legal drafts and 404) rendered with headings.
- Course search returned the matching course, excluded nonmatches, and displayed the empty state.
- Contact fields validated and displayed the preview-only success notice; no data was sent.
- Public homepage document width did not exceed viewport at 320, 375, 430, 768, 1024, 1280 and 1440px; mobile navigation opened.
- All seven workspace roles rendered their role heading, demo banner and expected navigation entries.
- Admin Students navigation opened its sample table; search filtered to one row and showed the empty state; create action was disabled.
- Dashboard document width matched viewport at 320, 375, 430, 768, 1024, 1280 and 1440px. Mobile sidebar opens at 375px.
- Built HTML smoke check confirmed homepage, about, teacher-solution and contact pages contain prerendered content and route-specific titles.

Visual review was completed on desktop public home and a narrow mobile dashboard. These checks do not replace an independent accessibility audit or full cross-browser/device testing.

## Backend/API/security tests

- Laravel route list contains the three documented `/api/v1` routes.
- `php artisan test --compact`: **5 passed, 17 assertions**, using PHPUnit's in-memory SQLite test configuration. Coverage includes public health, unauthenticated denial, current-user credential omission and active-membership/tenant scoping including suspended organizations.
- `vendor/bin/pint --test`: passed.
- PostgreSQL migrations have not been run; no local PostgreSQL service was confirmed.
