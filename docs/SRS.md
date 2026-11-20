# Software Requirements Specification

**Version:** 0.1 · **Status:** Baseline; refine during implementation

## System context

The planned system is a public React web experience, role-based web application, versioned Laravel REST API, PostgreSQL database, queue/cache infrastructure as justified, private object storage, and React Native clients. The repository implements the public frontend, seven dashboard roles, connected Laravel auth and tenant-scoped ERP/LMS workflows, plus an Expo mobile vertical slice. The complete API suite and migrations pass locally on SQLite and disposable PostgreSQL 17; production database validation, hosted PostgreSQL 16 CI, deployment and several advanced modules remain open. See `IMPLEMENTATION_STATUS.md` for verified scope.

## Functional requirements

1. Visitors can navigate the statically rendered public product pages and search, filter and paginate explicitly published public course listings by level and subject. Course detail pages show published lesson titles and offer contextual enquiry; public self-enrollment and educator profiles are not available yet. Authorized organization staff can assign course enrollment.
2. Visitors can submit a consented contact enquiry to the throttled API; staff review is restricted to an allowlisted verified platform operator, and email delivery requires configuration.
3. Users can register, verify email, sign in, recover passwords, and select active organization memberships; real email delivery and broader identity controls remain deployment requirements.
4. Authenticated users see only workspaces and records allowed by active organization membership, role/resource policy and supported membership capabilities. Organization membership and platform status changes are audited; domain authorization remains enforced by resource-specific API policies.
5. Administrators and authorized managers maintain organization settings, academic sessions, branches, classes, sections, subjects, batches, student/teacher rosters, teaching assignments, invitations and direct guardian links. Attendance, timetable, fees, expenses, documents and organization reports are tenant-scoped; some broader attendance and roster-reconciliation workflows remain open.
6. Course editors manage courses, ordered modules and lessons, private resources, enrollment, assignments, submissions, question banks, quizzes and results. Institution examinations support assigned-teacher mark entry, section results and controlled publication. Some storage, malware scanning and long-term retention controls remain open.
7. Students access published learning content, submit assignments, take scheduled quizzes, complete lessons and view only their own results and progress. Course enrollment is currently assigned by authorized organization staff.
8. Parents access only records of explicitly linked children permitted by the institution, including published academic results, attendance, homework, fees and notices.
9. Management views authorized aggregate academic, attendance, course-progress and finance reports; can manage scoped meetings and notices; and can access membership data only where granted.
10. An explicitly allowlisted, verified platform operator can search users/organizations, suspend or reactivate ordinary accounts and organizations with a required reason, issue and review staff invitations, revoke unaccepted invitations with a required reason, and review the corresponding audit history. Revocation preserves the invitation record and invalidates its token. Invitees create their own account and accept membership through the existing invitation flow. Self-service profile management and constrained organization membership permissions are implemented. A dedicated invitation resend action, direct account mutation, broader role configuration, billing controls and system settings remain planned.
11. Integrations use provider abstractions, explicit configuration, safe failure handling and appropriate consent.

## Quality requirements

- Responsive at 320, 375, 430, 768, 1024, 1280 and 1440 CSS pixels; no unintended horizontal overflow.
- Target WCAG 2.2 AA where practical; semantic structure, keyboard use, contrast and focus visibility.
- API authorization and tenant scoping are enforced server-side and covered by cross-tenant tests.
- Secrets remain in deployment secret stores; uploads are validated and private by default.
- Paginate list APIs, index tenant and relationship keys, avoid N+1 queries and queue heavy jobs.
- Record operational health, errors and backups without exposing learner information in logs.

## Current website acceptance

All defined public routes render; navigation adapts to small screens; public course listings use privacy-filtered API responses; consented enquiries persist and report notification status; sample course/pricing content is labelled; legal pages show draft status; and unavailable routes show a useful 404. The serial Chromium suite passes **23 tests**, including public catalog and enquiry flows, local account verification/sign-in, management inbox read receipts, stable roster pagination, field-level CSV reconciliation with selected updates, and formula-safe CSV export, keyboard skip navigation, dashboard previews, Google Meet scheduling, attendance correction and 320px/1440px route-overflow checks. TypeScript, ESLint and the production build pass. The full Laravel suite passes on in-memory SQLite and disposable PostgreSQL 17; persistent production-shaped database, hosted PostgreSQL 16 CI and production deployment verification remain open.

## Open questions

Detailed institution workflows, role/permission matrix, retention and deletion, identity provider and MFA policy, organization invitation/verification rules, course enrollment model, fee accounting scope, languages, SLA and final pricing remain to be agreed through product discovery.
