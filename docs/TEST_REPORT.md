# Test Report

**Updated:** 18 November 2026

## Automated checks

On 18 November 2026, `npm run typecheck`, `npm run lint` and `npm run build` passed after the public-site static prerendering and role-dashboard implementation. The production build generated 14 route-specific public HTML pages. Checks were rerun after the final role-switch remount change; record any later rerun below.

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

Not applicable yet. API, authentication, tenant isolation, file access and payment tests require corresponding backend implementation.
