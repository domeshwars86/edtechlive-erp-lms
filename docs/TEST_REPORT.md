# Test Report

**Updated:** 18 November 2026

## Live staff, educator, teacher, student, and parent overview milestone (18 November 2026)

- Full PHPUnit Feature suite — **70 passed / 477 assertions** using in-memory SQLite. Dashboard summary checks cover active roster counts, tenant isolation, organization-local timetable and attendance aggregation, teacher assignment/grading scope, educator courses/learners/reviews/meetings, student learning activity, linked-child-only parent summaries, empty attendance, and role scoping.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; the production build prerendered 14 public routes.
- `vendor/bin/pint --test` passed. No database migration was needed; PostgreSQL and browser visual/accessibility checks remain open.

## Meetings, fees, and notification center milestone (18 November 2026)

- Full PHPUnit Feature suite — **63 passed / 421 assertions** using in-memory SQLite, including notification list/read isolation across users and organizations.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; Vite prerendered 14 public routes.
- `vendor/bin/pint --test` — passed. The notifications migration was applied to the ignored local SQLite development database. PostgreSQL validation, configured-mail delivery and browser visual review remain separate gates.

## Course progress reporting milestone (18 November 2026)

- Full PHPUnit Feature suite — **63 passed / 427 assertions** using in-memory SQLite. Added access and aggregate checks for the organization course progress report.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; Vite prerendered 14 public routes.
- `vendor/bin/pint --test` passed. The report is read-only and has no schema migration.

## Guardian relationship management milestone (18 November 2026)

- Focused guardian API suite — **2 passed / 15 assertions** using in-memory SQLite; covers primary-contact replacement, unlinking, cross-organization rejection, and student-role denial.
- Full PHPUnit Feature suite — **65 passed / 443 assertions**. `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered public routes), and `vendor/bin/pint --test` passed.
- Guardian management has no schema migration. PostgreSQL and browser visual/accessibility validation remain open.

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

## Teaching assignment milestone (18 November 2026)

- Full PHPUnit suite — **30 passed / 145 assertions** using in-memory SQLite. New coverage checks idempotent assignment creation, session/section consistency, removal, and student-role denial.
- Timetable creation now requires a matching active teacher/subject/section/session assignment whenever a teacher is selected.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; Vite prerendered 14 public routes. `vendor/bin/pint --test` passed.
- At this point in the implementation, staff account invitations/linkage and teacher-owned attendance remained future work; they are covered by the following milestone.

## Organization invitation and teacher access milestone (18 November 2026)

- Full PHPUnit suite — **39 passed / 197 assertions** using in-memory SQLite. Coverage includes invitation listing/sending/rejection/expiry/revocation, one-time acceptance for new and existing accounts, teacher roster linking, teacher assignment visibility, timetable filtering, and section-scoped attendance permissions.
- `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered routes), and `vendor/bin/pint --test` passed after the invitation and teacher workspace integration.
- The organization invitation migration was applied to the ignored local SQLite development database. Email delivery remains configured to the local log transport; no real message was sent.

## Existing QA and known dependency issue

## Private course material milestone (18 November 2026)

- Full PHPUnit suite — **53 passed / 328 assertions** using in-memory SQLite. Coverage includes private file upload, publication state, enrollment-scoped metadata/downloads, cross-course denial, and `nosniff` response headers.
- `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered public routes), and `vendor/bin/pint --test` passed after adding multipart uploads to the course workspace.
- Files are stored in a private local disk during development. Durable production storage and malware scanning have not been configured.

## Contact enquiry milestone (18 November 2026)

- Full PHPUnit suite — **56 passed / 343 assertions** using in-memory SQLite. New tests cover persisted consented intake, required email/consent validation, and honeypot drops.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; build prerendered 14 public routes. `vendor/bin/pint --test` passed after import ordering was corrected.
- `POST /api/v1/enquiries` is registered with a six-per-minute throttle. The contact-enquiry migration was applied to the ignored local SQLite development database.
- Local mail uses the log transport; no real email was sent. PostgreSQL, staff review UI, retention cleanup and production delivery remain open.

## Learner quiz result history (18 November 2026)

- Student quiz workspace now loads the authenticated student's submitted-attempt history from the existing scoped API and displays each score/date; instructor result review remains available.
- Added API assertion that a student sees their own submitted history while learner identity fields stay private in that response.
- Full PHPUnit suite — **56 passed / 347 assertions**. `npm run typecheck`, `npm run lint`, `npm run build` (14 public routes) and `vendor/bin/pint --test` passed.

## Timed quiz and auto-scored attempt milestone (18 November 2026)

- Full PHPUnit suite — **51 passed / 313 assertions** using in-memory SQLite. Tests cover private answer keys, published-quiz requirements, enrollment checks, attempt limits, server-side scoring, and teacher result visibility.
- `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered public routes), and `vendor/bin/pint --test` passed after adding the quiz builder and student attempt workspace.
- Quiz/question/attempt migration applied to the ignored local SQLite development database. PostgreSQL and browser visual verification remain unverified.

## Assignment submission and grading milestone (18 November 2026)

- Full PHPUnit suite — **49 passed / 286 assertions** using in-memory SQLite. Coverage includes enrollment-gated assignment reads/submissions, teacher course ownership, score caps, feedback, locked graded submissions, and publication gating on the course state.
- `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered public routes), and `vendor/bin/pint --test` passed after connecting teacher/admin authoring and student submission workspaces.
- The assignment/submission migration was applied to the ignored local SQLite development database. PostgreSQL and browser visual verification remain unverified.

## Course and lesson LMS foundation (18 November 2026)

- Course/lesson/enrollment API added with teacher ownership checks, manager enrollment, and student enrollment visibility rules.
- Full PHPUnit suite — **46 passed / 263 assertions** using in-memory SQLite, including HTTPS-only resource validation, teacher course ownership, enrollment scope, and student visibility.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed after the course workspace was added; the build prerendered 14 public routes.
- The course/lesson migration was applied to the ignored local SQLite database. PostgreSQL deployment validation and browser visual review remain unverified.

## Student, family access and attendance reporting milestone (18 November 2026)

- Full PHPUnit suite — **44 passed / 239 assertions** using in-memory SQLite. Added coverage for student-to-roster linking, parent-selected child links, student/parent attendance scope, and date-filtered attendance aggregation/rates.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; the production build prerendered 14 public routes.
- `vendor/bin/pint --test` passed. No real email was sent; local mail remains configured to the log transport. PostgreSQL and production deployment remain unverified.
- Initial PHPUnit invocation without the documented test environment produced a CSRF 419 in the browser-session test; rerunning with the in-memory SQLite, array session/cache/mail, and sync queue settings passed the full suite.

Browser preview checks from 18 November 2026 previously reviewed public routes, course filtering and all seven dashboard preview roles across representative viewport widths. This milestone's browser check focused on the new account forms and was not a full responsive regression pass.

`npm audit` identified nine high findings in the Vite/TypeScript-ESLint dependency tree via `picomatch@4.0.7`. The registry reported 4.0.7 as the latest picomatch release and recommended an incompatible Vite 6.2.7 downgrade; that downgrade was not applied. Recheck when compatible upstream fixes are available.

## Student course progress milestone (18 November 2026)

- Added tenant-aware lesson completion records. Enrolled learners can mark/reopen published lessons and see a course completion count/percentage; course teachers and managers can review learner percentages for their authorized course. Draft lessons and inactive/non-enrolled courses are denied.
- Full PHPUnit suite — **58 passed / 366 assertions** using in-memory SQLite. `npm run typecheck`, `npm run lint`, `npm run build` (14 public routes), and `vendor/bin/pint --test` passed.
- Progress migration applied to the ignored local SQLite development database. PostgreSQL migration validation and staff-wide progress reports remain open.

## Manual fee ledger milestone (18 November 2026)

- Added organization-scoped INR fee records, staff-entered partial/final receipts, student read-only views, and explicitly linked parent views. Transactions reject payments beyond the remaining balance; no gateway charge is initiated.
- Full PHPUnit suite — **60 passed / 386 assertions** using in-memory SQLite. Coverage includes partial/final balances, overpayment rejection, student/parent scope, and write-role denial.
- `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered public routes), and `vendor/bin/pint --test` passed. Fee migrations applied to the ignored local SQLite development database.
- Payment gateway, refunds, reconciliation, PostgreSQL validation, and browser visual review remain open.

## Manual class and management meetings (18 November 2026)

- Added tenant-scoped meeting schedules with HTTPS-only links, class/management types, teacher assignment checks, learner/guardian visibility, overlap detection under resource locks, and cancellation.
- Full PHPUnit suite — **62 passed / 411 assertions** using in-memory SQLite. Coverage includes teacher/student/parent visibility, management meeting privacy, insecure URL rejection, overlap rejection, and cancellation permissions.
- `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered public routes), and `vendor/bin/pint --test` passed. Meetings migration applied to ignored local SQLite.
- Meet room provisioning, notices/reminders, attendance sync, recording uploads, PostgreSQL, and browser visual review remain open.
