# Implementation Status

**Updated:** 18 November 2026

## Current milestone

Phase 6 — LMS foundation. The public website and seven role dashboard layouts are in place. Registration, login, verification, recovery, organization membership/invitations, academic masters, rosters, assignments, attendance, timetable, student/guardian access, attendance reports, and course/lesson/enrollment, assignment/submission/grading, timed quiz workflows, and private study asset APIs use Laravel APIs. Teacher attendance and courses are profile-scoped; student and parent views are account/link scoped. Broader exams/results and overview metrics remain illustrative. PostgreSQL and production email delivery remain unverified.

## Implemented

- Responsive, statically pre-rendered public website and all seven role dashboard previews.
- Laravel 13.43 API with Sanctum session/token authentication, throttled registration/login/recovery, signed email verification, password reset and membership-scoped organization reads.
- User, organization and membership models/migrations; organization registration creates an initial active membership transactionally.
- Web client API helper uses credentialed Fetch, Laravel CSRF cookies, and same-origin Vite proxy configuration for local development.
- Login session is restored from `/api/v1/me`; logout calls API; registration, forgot/reset password and resend verification invoke the corresponding API endpoints.
- Organization selection is loaded from the authenticated user's active memberships. Connected dashboard headers use the account and organization from API state; dashboard business data remains clearly labelled demonstration data.
- Tenant-scoped academic session, class, section, and subject migrations, models, and API routes are implemented. Active organization members can read; admin, management, and independent educator roles can create/update. Cross-tenant references are rejected.
- The admin dashboard includes create/list/archive flows for sessions, classes, sections, and subjects, backed by the Laravel API.
- Admins and management can create, search, and activate/deactivate tenant-owned student and teacher roster profiles; student placement references are checked against organization-owned classes, sections, and sessions.
- The admin workspace can take and update paginated daily attendance registers for active students in a section/session, constrained to session dates. It can create, list, and deactivate weekly timetable entries with section/session checks, HTTPS meeting links, and overlap detection.
- Admins can assign active teachers to subject/section/session combinations; timetable entries with a teacher require the matching assignment.
- Admins and management can send, list, and revoke organization invitations. Seven-day, one-time tokens are hashed in storage; new invitees create verified accounts, existing invitees confirm their password or accept while signed in, and teacher profiles are linked or created.
- Teachers can read only their linked teaching assignments and timetable entries. They can take attendance only for assigned sections and sessions through the teacher workspace.
- Student invitations require an active matching roster profile and link the accepted account to it. Parent invitations identify active students to link; parent views expose only those explicit links.
- Admin, management, and independent educator can view a date-filtered attendance report with organization-scoped totals and class/section breakdowns in the dashboard.
- Managers and linked teachers can create draft courses, publish lessons with written content or an external HTTPS resource, upload private course files, publish assignments, review submissions, and author MCQ quizzes. Managers can publish a course after it has a published lesson and enroll active student roster records. Students can read published lessons and download published materials only when their linked student profile has an active course enrollment.
- Public contact enquiries are rate-limited, consent-checked, honeypot-filtered, persisted, and optionally sent to a configured inbox; the form reports whether email notification was actually sent.
- API and web setup/auth configuration is documented in `.env.example` files and `apps/api/README.md`.

## Current limitations

- API database migrations have not been applied to PostgreSQL; local PostgreSQL service status was not confirmed.
- Email delivery must be configured for verification, reset, and organization invitation links; local development logs messages.
- Contact enquiries are stored through a public throttled API after explicit consent and can notify a configured recipient. Staff review UI and retention cleanup remain open.
- Dashboard overview counts/activity and broader exam/results workflows remain sample data. LMS covers course/lesson authoring and publication, manual enrollment, text assignments, student submissions, grading, and feedback; timed MCQ quizzes use private answer keys and server-side scoring, and course files are stored privately behind authorized downloads. Modules, malware scanning, durable production file storage, broader exams and progress are not implemented.
- Student account and guardian links are currently established through invitations only; direct guardian management, changing or removing links, student roster reconciliation, and consent/retention workflows remain future work. Organization switching API, granular policies, audit trail, MFA, and remaining academic entities also remain future work.
- Sitemap host must be replaced with the eventual production domain; legal drafts need review.
- npm audit has nine high severity findings through `picomatch@4.0.7`; do not apply its incompatible Vite 6.2.7 downgrade without an upstream-compatible fix.

## Verification

- LMS foundation verification: full PHPUnit suite **53 passed / 328 assertions** using in-memory SQLite; `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered public routes), and `vendor/bin/pint --test` passed. Course/lesson/enrollment and assignment/submission, quiz/attempt, and course-asset migrations applied to the ignored local SQLite development database.
- `npm run typecheck` — passed after invitation and teacher workspace integration.
- `npm run lint` — passed after invitation and teacher workspace integration.
- `npm run build` — passed after invitation and teacher workspace integration; prerendered 14 routes.
- Browser accessibility inspection confirmed login and registration screens render locally; no credentials or account creation were submitted. Full session verification needs a persistent local API database and mail transport.
- Full PHPUnit suite — 39 passed / 197 assertions using in-memory SQLite. The normal `php artisan test` runner cannot read its configured PHPUnit bootstrap in the current sandbox; PHPUnit passed with Composer autoload preloaded and safe in-memory DB environment settings.
- PHP syntax checks passed for application, migration, routes, and test files. Academic, people, attendance, timetable, teaching assignment, and invitation migrations are applied to the ignored local SQLite development database; organization routes are registered.
- Local HTTP smoke check — admin workspace page and Vite-proxied Laravel health endpoint both returned 200.
- `vendor/bin/pint --test` — passed after correcting import order. No PostgreSQL migration or deployment has been performed.

## Next

1. Verify client auth flows against a persistent local development database and configure a safe local PostgreSQL service before migration validation.
2. Continue LMS workflows with broader examinations, quiz result history, learning progress, and modular course organization.
3. Expand reports to enrollment and timetable/workload metrics.
4. Build the staff enquiry review/response workflow and define enquiry retention; configure real email delivery before promising a response.
5. Reassess npm audit advisories when compatible upstream dependency releases are available.
