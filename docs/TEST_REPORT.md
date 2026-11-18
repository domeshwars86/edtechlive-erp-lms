# Test Report

**Updated:** 19 November 2026

## Continuous quality workflow milestone (18 November 2026)

- Added a GitHub Actions quality workflow for root web typecheck/lint/build, Laravel PHPUnit/Pint with in-memory SQLite, and mobile typecheck/Android bundle export. It is push/PR triggered, read-only for repository contents, and has no deployment step.
- Workflow YAML parsed locally and its web/mobile commands passed in this environment. A hosted GitHub Actions run cannot be triggered from this workspace because no remote CI session/credentials are connected.
- Current backend regression recheck: full Laravel suite **76 passed / 493 assertions** against in-memory SQLite; `vendor/bin/pint --test` passed. Running PHPUnit with the normal sandbox token was blocked by PHP `is_readable` on workspace files; the same read-only repository check and test run passed with the authorized user token.
- Local browser reachability follow-up: Vite and Laravel report ready on ports 4173 and 8001 when launched from this shell, but both direct HTTP probes and Windows Chrome time out. This shell/browser network boundary prevents confirming browser access; stopgap local URL remains `http://127.0.0.1:4173` when both processes run on the user's machine.

## Expo mobile foundation milestone (18 November 2026)

- Added `apps/mobile`, an Expo SDK 56 / React Native 0.85 app with verified API sign-in, SecureStore token persistence, organization selection, scoped role summaries, course lists and published lesson reading.
- Mobile `npm run typecheck` passed on Node 20.19.4. `npx expo install --check` found dependencies aligned with the local SDK 56 compatibility map; Expo noted network access was disabled, so remote validation was unavailable.
- `npx expo export --platform android --no-bytecode --no-minify` passed and emitted the Android JavaScript bundle (564 modules, 2.4 MB). Bytecode was disabled for this sandbox export because the Hermes temp output path was inaccessible; this is a bundle check, not a signed/native APK build.
- Native Android/iOS builds and device flows are not verified because Android SDK/emulator and Xcode toolchains are unavailable here. npm audit could not contact the registry (DNS unavailable).
- Follow-up mobile LMS milestone: mobile TypeScript check and Android JavaScript export passed after wiring student written submissions, server-scored timed quiz attempts/result history, and scoped in-app notification read receipts. Native/device verification remains open.
- Teacher mobile attendance milestone: mobile TypeScript check and Android JavaScript export passed with assignment-scoped class registers, required status selection for each learner, and API upsert saves. No native device or live API session is available here; backend authorization and attendance validations are covered by the existing API feature suite.
- Mobile meeting access milestone: mobile TypeScript, Android bundle export and root ESLint passed. The app loads the membership-scoped meeting list, displays upcoming times, and only offers the manual HTTPS join link while the scheduled meeting is live. Provider room creation and native device verification remain untested.
- Mobile teacher grading milestone: mobile TypeScript, Android JS bundle export and root ESLint passed after connecting course-scoped submission review and score/feedback writes to the existing teacher-authorized APIs. Server-side maximum-score and ownership validation remains authoritative; no live learner records were accessed.
- Mobile family finance and attendance milestone: mobile TypeScript, Android JS bundle export and root ESLint passed after connecting student profile/attendance/fee reads and parent linked-child attendance/fee reads. API tests cover the underlying student/guardian privacy scopes; no production/student data was used.
- Mobile assessment history milestone: students and parents can read quiz scores and graded assignment scores/feedback in the Expo family workspace. Mobile TypeScript, Android JavaScript export (564 modules), and root ESLint passed; the bundle export used a workspace-local temporary directory because the default Windows sandbox temp path denied Hermes writes. Native devices and live student data were not used.
- Mobile family progress milestone: students and parents can read published-course lesson completion in their scoped mobile workspace. Mobile TypeScript, Android JavaScript export (564 modules), and root ESLint passed; the export bundle was removed after verification and native/device builds remain unverified.
- Mobile verification recovery milestone: added an explicit resend-verification action for unverified sign-in attempts using the existing throttled endpoint and its non-enumerating response. Mobile TypeScript, Android JS bundle export and root ESLint passed. No email was sent during verification.

## Contact enquiry inbox milestone (18 November 2026)

- Focused contact enquiry inbox suite — **4 passed / 14 assertions**. Covers allowlisted access, search/status filters, denied non-operators, valid status changes with reviewer audit, and invalid filters/status values.
- Full PHPUnit Feature suite — **74 passed / 491 assertions** using in-memory SQLite; `vendor/bin/pint --test` passed.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; production build prerendered 14 public routes.

## Live dashboard timetable and family updates milestone (18 November 2026)

- Replaced the connected dashboard lower sample table with role-scoped upcoming timetable entries for admin, management, teacher, and educator roles; student and parent dashboards now show their private recent notifications. Demo workspaces retain labeled sample rows.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; production build prerendered 14 public routes. The full PHPUnit Feature suite passed — 74 tests / 491 assertions; timetable and notification role/tenant scope are covered.

## Workspace chooser and local run alignment (18 November 2026)

- Dashboard workspace control now reopens the authenticated membership chooser and navigates to the selected membership's role workspace.
- Vite's default API proxy is aligned with the local Laravel port (8001); local frontend preview uses port 4173 to avoid occupied/reserved ports. Browser reachability from the Windows Chrome process could not be verified in this environment.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed; the production build prerendered 14 public routes.

## Dashboard schedule integration milestone (18 November 2026)

- Dashboard schedule integration: role-scoped upcoming meetings now load from the organization meetings API, with loading/error/empty states and join links enabled only during the scheduled meeting window. `npm run typecheck`, `npm run lint`, and `npm run build` passed; build prerendered 14 public routes.

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

An earlier audit identified nine high findings through `picomatch@4.0.7`; the incompatible Vite downgrade it suggested was not applied. The latest registry-backed root audit on 18 November 2026 reports zero vulnerabilities.

## Dependency security and local account setup (18 November 2026)

- Patched the Expo SDK 56 transitive CLI dependencies with narrowly scoped npm overrides: `glob@14.0.2`, `minimatch@11.0.1`, and CLI-only `zod@4.6.6`.
- A clean mobile `npm ci` completed from the checked-in lockfile. With Node 20.19.4, mobile TypeScript verification passed and Expo exported the Android JavaScript bundle (564 modules). Registry-backed mobile `npm audit --omit=dev` and root `npm audit` each reported **zero vulnerabilities**.
- Added a Node engine range to the mobile package to match the React Native/Metro requirement and expanded fresh-checkout setup steps for both local servers and SQLite.
- In development, web account network failures now point to the Laravel API on port 8001; production retains a general service-unavailable message.
- Root typecheck, lint and production build passed (14 prerendered public routes); API regression suite passed (**76 tests / 493 assertions**) and Pint passed. Expo SDK dependency check passed using the bundled dependency map; online Expo version validation was unavailable.
- Browser account creation remains unverified here: the isolated shell server was not reachable from Windows Chrome, so no live sign-up or verification mail was submitted.

## Live dashboard learning activity (19 November 2026)

- Added `GET /api/v1/organizations/{organization}/dashboard/activity`, returning seven organization-local daily totals for published lesson completions and assignment submissions. It returns aggregate counts only and enforces active enrollment, organization scope, teacher course ownership, student self scope, and explicit parent-child links.
- Added timestamp indexes used by the seven-day aggregate and account/role/organization cache scoping in the dashboard chart.
- Applied the additive index migration to the configured local SQLite database after confirming all earlier migrations were already applied; no existing records were changed.
- Connected the web dashboard activity chart to the new API for signed-in organization members. The chart now has count-based axes, accurate series labels, loading/error/empty states, and a clearly labeled sample for preview-only workspaces. Replaced the stale hard-coded dashboard date with a client-local date rendered after hydration.
- Added feature tests for local timezone boundaries and student record isolation. Full API suite: **78 tests / 502 assertions**; Pint passed. Web typecheck, lint, and production build passed; the build prerendered 14 public routes.
- Browser visual/authenticated regression remains unavailable because Windows Chrome cannot reach servers launched in the isolated shell.

## Modular course organization (19 November 2026)

- Added tenant- and course-scoped ordered modules, create/update routes, and optional lesson association. Students see modules only for enrolled published courses, and only when the module contains a published lesson; module descriptions/order flow into course screens.
- Connected manager/teacher screens to create, edit, order modules and assign lessons; student course content is grouped by module. Lessons without a module remain visible in an “Other lessons” group.
- Added API coverage for cross-course module rejection, teacher course ownership, and published-module student visibility. Full API suite: **79 tests / 527 assertions**; Pint passed. Web typecheck, lint and production build passed with 14 prerendered public routes.
- Applied the additive module/lesson migration to local SQLite after confirming prior migrations were applied. PostgreSQL validation and browser regression remain outstanding.

## Enrollment placement report (19 November 2026)

- Added `GET /api/v1/organizations/{organization}/reports/enrollment` for admin, management, and independent educator roles, with active learner totals by academic session and section, unassigned student count, and active enrollment totals for published courses. The response contains aggregate counts and no student identifiers.
- Added an Enrollment tab to the admin/management academic reports workspace. It shows placement, capacity, published-course enrollment totals, and current/inactive session markers.
- Added tests for tenant isolation, session/section aggregation, published-course enrollment counts, and teacher denial. Full API suite: **81 tests / 538 assertions**; Pint passed. Web typecheck, lint and production build passed with 14 prerendered public routes.

## Teacher workload report (19 November 2026)

- Added `GET /api/v1/organizations/{organization}/reports/workload`, restricted to admin, management, and independent educator roles. It reports active teachers' distinct session/section assignments, per-teacher active roster reach, active weekly timetable slots and scheduled hours for sessions in progress on the organization's local date.
- Excludes inactive teachers, sections, timetable slots, and sessions outside the local date window. Teacher and learner accounts are denied organization-wide totals.
- Added the Teacher workload tab to the admin/management academic reports workspace. Focused API tests pass (**2 tests / 11 assertions**); full API suite passes (**83 tests / 549 assertions**), Pint, web typecheck/lint, and production build (14 prerendered routes).
- No migration was required. Browser visual verification remains unavailable in this environment.

## Finance summary report (19 November 2026)

- Added `GET /api/v1/organizations/{organization}/reports/finance` for admin, management, and independent educator roles. It summarizes INR receivables, staff-entered receipts, remaining balance, fee status counts, and overdue balances using the organization's local date. A validated local-date filter (up to 366 days) summarizes receipt count/amount for the selected range; default is the last 30 days.
- The report returns aggregate values only and is tenant-scoped. A manager-side overview is displayed above the fee ledger; family fee views remain individually scoped. No payment provider or charge flow was added.
- Focused API tests pass (**3 tests / 25 assertions**) covering all-time and period totals, timezone-aware day boundaries, date validation, overdue partial balance, foreign-tenant exclusion, and teacher denial. Full API suite passes (**88 tests / 596 assertions**), Pint, web typecheck/lint, and production build (14 prerendered routes).

## Assessment outcome report (19 November 2026)

- Added `GET /api/v1/organizations/{organization}/reports/assessments` and an Assessments tab to the academic reports dashboard. Published quizzes show submitted and in-progress attempts, learner participation counts, and weighted average scores. Published assignments show submissions, graded/awaiting-review counts, learner participation, and weighted score averages. No student identity is returned.
- Connected parent Examination results and student Results dashboards to child/self-scoped result APIs. Responses contain only published-course submitted quiz scores and graded assignment scores/feedback; no answer or submission text is returned. Focused family-result tests pass (2 tests / 13 assertions), and the complete API suite passes (90 tests / 609 assertions). TypeScript, ESLint, production build (14 prerendered routes), and Pint pass.
- Admin, management, and independent educator roles see tenant totals. Teacher access is limited to published courses assigned to that teacher. Student and parent accounts are denied.
- Focused API tests pass (**2 tests / 22 assertions**) covering quiz and assignment weighted score totals, in-progress handling, grading backlog, teacher course scoping, and student denial. Full API suite passes (**87 tests / 583 assertions**), Pint, web typecheck/lint, and production build (14 prerendered routes).

## PostgreSQL CI gate (19 November 2026)

- Added a separate PostgreSQL 16 service job to `.github/workflows/quality.yml`. It installs `pdo_pgsql`, applies a fresh schema to a disposable CI database, then runs the complete API test suite.
- Workflow YAML parses with the installed `js-yaml` parser. The job has not executed from this checkout; PostgreSQL 17 client/server tools are installed outside `PATH`, but no server responds on port 5432, service-manager access is denied, and Docker is unavailable. PostgreSQL compatibility remains unverified until the CI job runs.

## Local development launcher (19 November 2026)

- Added `npm run setup:local` to create a local-only SQLite API configuration when no `.env` exists, generate an app key if missing, create the ignored database file, and apply additive migrations. It refuses non-local environments, non-SQLite connections, alternate database paths, and non-empty `DB_URL` values.
- Added `npm run dev:stack` to start Laravel and Vite together, stop both on exit, and support alternate ports through `EDTECHLIVE_API_PORT` and `EDTECHLIVE_WEB_PORT`.
- `npm run setup:local` completed successfully (`Nothing to migrate`). `npm run dev:stack` launched Laravel and Vite and both printed ready messages on alternate ports. Direct HTTP requests are denied by the sandbox socket policy; browser automation also stopped before navigation because it could not verify the current browser URL.

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

## Family assessment results and cash outflows (19 November 2026)

- Parent and student dashboard result views return submitted quiz outcomes and graded assignment scores/feedback under explicit student or guardian scope. Responses omit answers and submitted work. Focused family coverage plus full API suite passed (**90 tests / 609 assertions**); TypeScript, ESLint, production build (14 prerendered routes), and Pint passed.
- Finance expense verification: **92 PHPUnit tests / 629 assertions** passed using in-memory SQLite, including tenant-scoped recording/listing, teacher/parent denial, organization-local date validation, and date-filtered/all-time net cash flow. Root TypeScript, ESLint, production build (14 prerendered routes), and Pint passed. The additive migration was applied through guarded local setup.
- Organization announcements: focused API tests cover delivery to an active role audience, absence from other roles' feeds, manager-only access and announcement history. Full API suite passed (**94 tests / 642 assertions**), Pint and root ESLint/typecheck passed, and the production build prerendered 14 routes with a 483.30 kB main JS chunk after lazy splitting the reports and announcement workspaces. The additive SQLite migration applied via guarded local setup.
- Deployment readiness check: a new public database readiness endpoint returns 200 when a simple query succeeds and a generic 503 with no internal connection details when it fails. Full API regression is green (**96 tests / 647 assertions**), Pint passes, and local setup reports no pending migrations.
- Family academic progress: the student and parent progress routes return only published lessons in active published-course enrollments; linked-child privacy and completion totals are covered. Full API suite passes (**97 tests / 656 assertions**), along with root TypeScript, ESLint, production build (14 prerendered routes; 485.69 kB main JS), and Pint.
- Parent homework: family views list published assignments for the selected child with due date, status, score and feedback while omitting submitted answer text. Full API suite passes (**98 tests / 667 assertions**); root TypeScript, ESLint, production build (14 prerendered routes; 487.66 kB main JS) and Pint passed.
