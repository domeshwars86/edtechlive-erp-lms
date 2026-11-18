# Implementation Status

**Updated:** 19 November 2026

## Current milestone

Phase 10 — mobile applications, initial vertical slice. The responsive public website, seven role dashboards, Laravel ERP/LMS APIs and connected web workflows are in place. The Expo client signs into the shared API, offers verification-link resend, stores its Sanctum token with SecureStore, selects organization membership, and provides role-scoped summaries, courses/lessons, student/parent profile and attendance views, read-only fee records, student assignment submissions, teacher grading, timed quiz attempts/results, recent notification read receipts, assignment-scoped teacher attendance, and upcoming scoped meeting links that can be joined only during the scheduled window. Native device builds remain open. Production email, PostgreSQL validation, external integrations and deployment remain unverified.

## Implemented

- Responsive, statically pre-rendered public website and all seven role dashboard previews.
- Laravel 13.43 API with Sanctum session/token authentication, throttled registration/login/recovery, signed email verification, password reset and membership-scoped organization reads.
- User, organization and membership models/migrations; organization registration creates an initial active membership transactionally.
- Web client API helper uses credentialed Fetch, Laravel CSRF cookies, and same-origin Vite proxy configuration for local development.
- Login session is restored from `/api/v1/me`; logout calls API; registration, forgot/reset password and resend verification invoke the corresponding API endpoints.
- Organization selection is loaded from the authenticated user's active memberships. Connected dashboard headers use the account and organization from API state; role-specific summary cards, seven-day learning activity and agenda panels use scoped API data, while the platform super-admin overview remains illustrative.
- Tenant-scoped academic session, class, section, and subject migrations, models, and API routes are implemented. Active organization members can read; admin, management, and independent educator roles can create/update. Cross-tenant references are rejected.
- The admin dashboard includes create/list/archive flows for sessions, classes, sections, and subjects, backed by the Laravel API.
- Admins and management can create, search, and activate/deactivate tenant-owned student and teacher roster profiles; student placement references are checked against organization-owned classes, sections, and sessions.
- The admin workspace can take and update paginated daily attendance registers for active students in a section/session, constrained to session dates. It can create, list, and deactivate weekly timetable entries with section/session checks, HTTPS meeting links, and overlap detection.
- Admins can assign active teachers to subject/section/session combinations; timetable entries with a teacher require the matching assignment.
- Admins and management can send, list, and revoke organization invitations. Seven-day, one-time tokens are hashed in storage; new invitees create verified accounts, existing invitees confirm their password or accept while signed in, and teacher profiles are linked or created.
- Teachers can read only their linked teaching assignments and timetable entries. They can take attendance only for assigned sections and sessions through the teacher workspace.
- Student invitations require an active matching roster profile and link the accepted account to it. Parent invitations identify active students to link; parent views expose only those explicit links.
- Admins, management, and independent educators can review, create, designate a primary contact for, and remove student/guardian links. Direct links require an active, verified parent membership within the same organization.
- Admin, management, and independent educator can view a date-filtered attendance report with organization-scoped totals and class/section breakdowns in the dashboard.
- Admin, management, and independent educator can view active student placement by academic session and section, unassigned learner totals, and active enrollments in published courses. The report is aggregate-only and denies teacher access to organization-wide counts.
- Admin, management, independent educator, and course-owning teachers can view published-course progress summaries; teacher reports are restricted to their own courses and omit individual learner identity.
- Authenticated admin and management dashboard cards use tenant-scoped active student/teacher counts, today's active timetable slots, and today's attendance summaries calculated in the organization timezone. Independent educators see published courses, unique enrolled learners, pending grading and upcoming live classes. Teacher cards count only the teacher's active assignments, linked learners, pending grading in their own courses, and assigned timetable slots for the week. Student cards use that student's active enrollments, recently completed lessons, outstanding assignments, and 30-day attendance only. Parent cards aggregate only explicitly linked active children and their outstanding assignments, attendance, and upcoming meetings. Missing attendance is shown as no rate, not 0%.
- Managers and linked teachers can create draft courses, publish lessons with written content or an external HTTPS resource, upload private course files, publish assignments, review submissions, and author MCQ quizzes. Managers can publish a course after it has a published lesson and enroll active student roster records. Students can read published lessons, download published materials, and record completion only when their linked student profile has an active course enrollment. Students and instructors can review submitted quiz attempt history within each quiz; course teachers/managers can view progress for that course's active learners.
- Public contact enquiries are rate-limited, consent-checked, honeypot-filtered, persisted, and optionally sent to a configured inbox; an authenticated platform-operator inbox filters and tracks status changes with reviewer audit fields. The form reports whether email notification was actually sent.
- Admins, management, and independent educators can create tenant-scoped INR fee receivables and record partial/final receipts already received. Students and linked parents can read their own fees; balances cannot be overpaid. No external payment is initiated.
- Admins/management can schedule class and management meetings using manual HTTPS links; teachers can schedule assigned class meetings, while students and linked parents see only their enrolled/linked classes. Overlap checks and cancellation are persistent; provider room creation is not connected.
- The dashboard notification bell lists and marks recipient-owned notifications within the active organization. Fee issuance/receipt and meeting scheduling create notices for relevant students, linked guardians, assigned teachers or managers. Email/push delivery, announcements, notification preferences and retention policies remain open.
- API and web setup/auth configuration is documented in `.env.example` files and `apps/api/README.md`.
- Multi-organization users can reopen the verified membership chooser from the dashboard workspace control. Selection changes the active role/workspace in the current frontend session; a persistent active-organization preference is not implemented.
- The Expo SDK 56 mobile app in `apps/mobile` uses the shared Sanctum API, Expo SecureStore token persistence, membership selection, role-scoped summaries, published lesson reading, student/parent profile and attendance reads, family-scoped fee records, student assignment submissions, scoped teacher grading, timed quizzes/results, notification read receipts, teacher attendance registers scoped to assigned sections, and role-scoped meeting details/join links. Android and iOS native toolchains/device builds are not available in this workspace.
- Connected dashboards load seven organization-local days of published lesson completions and assignment submissions from a new aggregate-only API. Admin/management/educator views are organization scoped, teachers see their published courses, students see their own activity, and parents see only explicitly linked children. Demo dashboards label sample activity; the dashboard date now reflects the current client date.
- Activity queries use organization/time indexes; chart state is keyed to the signed-in account, role, and selected organization to avoid showing prior workspace data during account switches.
- The dashboard activity index migration has been applied to the configured local development SQLite database. PostgreSQL migration validation remains open.
- The mobile dependency tree now overrides vulnerable Expo transitive copies with patched `glob@14.0.2`, `minimatch@11.0.1`, and Expo CLI-scoped `zod@4.6.6`; a registry-backed `npm audit --omit=dev` reports zero vulnerabilities. Expo Android bundle export and TypeScript verification passed with Node 20.19.4.
- Web account connection errors now tell local developers to start the Laravel API on port 8001. Root setup documentation spells out running both servers and configuring a local SQLite database.

## Current limitations

- API database migrations have not been applied to PostgreSQL; local PostgreSQL service status was not confirmed.
- Email delivery must be configured for verification, reset, and organization invitation links; local development logs messages.
- Contact enquiries are stored through a public throttled API after explicit consent and can notify a configured recipient. Staff inbox access requires a verified account whose email is listed in `ENQUIRY_ADMIN_EMAILS`; retention cleanup remains open.
- Fee records are manual ledger entries only. Payment gateway integration, refund handling, idempotency, reconciliation reporting, and a dedicated finance role remain open.
- Live/management meetings use links created with an external provider. Automated Meet room provisioning, attendance sync, participant invites and recording workflow remain open.
- In-app notifications cover fee and meeting events only; email/push delivery, broader event coverage, announcements, preferences and retention cleanup are not implemented.
- Super-admin overview counts/activity and several broader exam/result workflows remain sample data. Admin, management, independent educator, teacher, student, and parent top-line metrics, seven-day lesson-completion/assignment-submission counts, and role-scoped upcoming meeting panels are live; role-scoped timetable entries and private family notifications feed the lower dashboard panel. LMS covers course/module authoring and ordering, grouped lesson publication, manual enrollment, text assignments, student submissions, grading, feedback, timed MCQ quizzes, per-quiz result history, private downloads, and course-scoped learner progress. Malware scanning, durable production file storage and broader examinations are not implemented.
- Student and guardian links can be established through accepted invitations or managed directly by authorized organization staff; student roster reconciliation, consent/retention workflows, persistent organization switching API, granular policies, audit trail, MFA, and remaining academic entities remain future work.
- Sitemap host must be replaced with the eventual production domain; legal drafts need review.
- Mobile source is verified through TypeScript and Android JavaScript bundle export; device/emulator verification and release signing remain outstanding. The latest registry-backed mobile production audit reports zero vulnerabilities.
- Latest root `npm audit` reports zero vulnerabilities across 168 installed dependencies. Continue periodic audits after dependency updates.

## Verification

- Added `.github/workflows/quality.yml`: web typecheck/lint/build, Laravel PHPUnit/Pint on SQLite, and Expo mobile typecheck/Android JS export. Workflow has read-only repository permissions and does not deploy.
- Latest backend regression recheck: **76 PHPUnit tests / 493 assertions** passed against in-memory SQLite, and Pint passed. This run used the authorized user token after the sandbox token made PHP report workspace files unreadable.
- LMS foundation verification: full PHPUnit suite **53 passed / 328 assertions** using in-memory SQLite; `npm run typecheck`, `npm run lint`, `npm run build` (14 prerendered public routes), and `vendor/bin/pint --test` passed. Course/lesson/enrollment and assignment/submission, quiz/attempt, and course-asset migrations applied to the ignored local SQLite development database.
- `npm run typecheck` — passed after invitation and teacher workspace integration.
- `npm run lint` — passed after invitation and teacher workspace integration.
- `npm run build` — passed after invitation and teacher workspace integration; prerendered 14 routes.
- Browser accessibility inspection confirmed login and registration screens render locally; no credentials or account creation were submitted. Full session verification needs a persistent local API database and mail transport.
- Full PHPUnit suite — 39 passed / 197 assertions using in-memory SQLite. The normal `php artisan test` runner cannot read its configured PHPUnit bootstrap in the current sandbox; PHPUnit passed with Composer autoload preloaded and safe in-memory DB environment settings.
- PHP syntax checks passed for application, migration, routes, and test files. Academic, people, attendance, timetable, teaching assignment, and invitation migrations are applied to the ignored local SQLite development database; organization routes are registered.
- Local HTTP smoke check — admin workspace page and Vite-proxied Laravel health endpoint both returned 200.
- `vendor/bin/pint --test` — passed after correcting import order. No PostgreSQL migration or deployment has been performed.
- Latest guardian-link milestone verification: **65 PHPUnit tests / 443 assertions**, frontend typecheck/lint/build, and Pint passed; local SQLite schema required no change.
- Mobile dependency remediation: scoped Expo CLI Zod 4 override and patched glob/minimatch overrides; a clean `npm ci`, Node 20.19.4 TypeScript check and Android export passed (564 modules), `npm audit --omit=dev` passed with zero vulnerabilities. Root `npm audit` also passed with zero vulnerabilities.
- After enrollment report integration, root typecheck, ESLint and production build passed (14 prerendered public routes); backend suite passed (**81 tests / 538 assertions**) and Pint passed. Tests cover tenant-scoped placement/enrollment totals and denial for teacher accounts. The course module migration is applied to local SQLite; Expo dependency compatibility check passed using its bundled offline SDK map.

## Next

1. Verify client auth flows against a persistent local development database and configure a safe local PostgreSQL service before migration validation.
2. Continue LMS workflows with broader examinations and assignment result history.
3. Expand reports to teacher workload and finance metrics.
4. Build the staff enquiry review/response workflow and define enquiry retention; configure real email delivery before promising a response.
5. Keep npm audit in the regular dependency maintenance cycle and review future override compatibility against the Expo SDK.
6. Extend mobile teacher grading, meeting and profile workflows, then verify on Android/iOS toolchains.
7. Add payment sandbox/gateway only after provider selection and finance workflow requirements are approved; connect meeting/email/push integrations after provider credentials and consent requirements are established.
