# Test Report

**Updated:** 20 November 2026

## Public keyboard navigation landmarks (20 November 2026)

- Added a visible-on-focus “Skip to content” link before the public header and dashboard sidebar; each link focuses its main landmark. Course detail content uses a section container rather than a nested `main` landmark. TypeScript and ESLint passed; the complete public-site Chromium spec passed (**15 tests in 1.5 minutes**), including first-tab focus, fragment navigation, target focus and exactly one main landmark on both public and dashboard shells.

## Canonical sitemap build handling (20 November 2026)

- Removed the committed reserved `.example` sitemap. The frontend build generates sitemap, canonical and Open Graph URLs only from `EDTECHLIVE_SITE_URL`, validates it as a public HTTPS origin, includes all 17 pre-rendered public routes, and adds an absolute sitemap reference to built `robots.txt`. With no configured origin it omits the sitemap and canonical metadata and emits a build warning. The production origin is not approved or configured in this repository.
- `npm run build` passed both with an approved-origin-shaped HTTPS value and with the origin unset. The configured build produced 17 parseable sitemap URLs, canonical and Open Graph tags on all 17 prerendered pages, and the expected absolute robots reference; reserved-origin validation rejected `.example`. The unconfigured build omitted sitemap/canonical tags and the local `/login` preview returned HTTP 200. `npm run lint` passed. The public-site Chromium suite passed **13 tests in 1.4 minutes**, including mobile navigation, auth verification resend, narrow viewport overflow and all seven dashboard previews.

## Password reset session handling (20 November 2026)

- A successful reset now ends the browser session when it belongs to the account whose password changed; resetting another account leaves the current session intact. Focused authentication tests passed (**10 tests / 66 assertions**), Pint passed for the changed PHP files, and the real Chromium registration, signed verification, login and password-reset session flow passed (**1 test in 1.5 minutes**). The browser check uses isolated local test services; hosted PostgreSQL 16 and production email delivery remain unverified.

## Self-service account profiles (19 November 2026)

- Signed-in users can change their display name and email after confirming their current password. Email changes normalize and enforce uniqueness, clear the verified timestamp, and send a fresh signed verification link; platform-operator allowlist addresses cannot be claimed or changed through this endpoint.
- The full Laravel suite passed (**176 tests / 1,540 assertions**) on in-memory SQLite and a fresh disposable PostgreSQL 17 cluster. The focused account profile suite passes (**5 tests / 38 assertions**) on SQLite, including password confirmation, hash update, current-token retention and other-token revocation. The real Chromium registration/auth flow passed (**1 test in 1.6 minutes**) and covers local signed email verification, session sign-in, sign-out before password recovery, password reset and sign-in, workspace selection, profile name/email/password change, email reverification, reload and sign-in with the new password; the account page also stayed within a 390px viewport. Web typecheck, ESLint, production build (17 prerendered routes), Playwright TypeScript check, and Pint passed. Hosted PostgreSQL 16 CI and live email delivery remain open.

## Mobile self-service account profile (19 November 2026)

- Added an Account screen to the Expo app for current-password-confirmed name/email updates and optional strong password changes. The screen opens a local signed email-verification link and can refresh the user's verification state. Mobile TypeScript check passed; Android JavaScript export bundled 564 modules (1.7 MB). Native device interaction and reachable verification-link behavior on a phone remain unverified.

## Mobile meeting scheduling (19 November 2026)

- Added schedule forms for Admin, Management, Independent Educator and assigned Teacher accounts. Managers may create management or class meetings; teachers are limited to their assigned class sections. Managers and meeting-owning teachers can cancel after confirming. Device-local start/end times are converted to UTC, and server-side checks enforce assignment, organization, HTTPS URL, time range, overlap and cancellation authorization. Mobile TypeScript check passed; Android JavaScript export bundled 564 modules (1.7 MB). Native interaction and live provider link opening were not exercised.

## Platform user account controls (19 November 2026)

- The verified platform operator can filter accounts by global status and suspend/reactivate ordinary accounts with a required reason. The change is audited with actor, target, reason and before/after status. The endpoint blocks self-changes and all allowlisted operator targets; suspended users cannot sign in or call authenticated APIs. The paginated audit UI now displays account changes beside organization status events.
- Focused platform tests passed (**6 tests / 76 assertions**) and auth tests passed (**9 tests / 59 assertions**). Full Laravel suite passed (**171 tests / 1,502 assertions**) on in-memory SQLite. The platform-account Chromium flow passed (**1 test in 5.9 seconds**) against the local preview with API calls mocked; it verified reason submission, status refresh, and account/reason display in audit history. Web typecheck, ESLint, production build (17 prerendered routes), Playwright TypeScript check, and Pint passed. `npm run setup:local` safely applied the new migration to ignored local SQLite. PostgreSQL validation remains open.

## Attendance correction history (19 November 2026)

- Attendance entries now require an explanation when staff change a previously saved status or note. The API stores a tenant-scoped append-only audit entry with actor, before/after values and reason in the same transaction as the edit. A section/date history endpoint enforces the existing role and teacher-assignment scopes; the web register prompts for a reason and displays the correction history.
- Full Laravel suite passed (**169 tests / 1,480 assertions**) on in-memory SQLite; the focused Attendance suite passed (**8 tests / 47 assertions**). The new correction route was verified in Chromium against the running local preview (**1 test**). Web typecheck, ESLint, production build (17 prerendered routes), Playwright TypeScript check, and Pint passed. PostgreSQL validation for this new migration remains open.

## Google Meet room creation (19 November 2026)

- Focused Google OAuth/Meet integration checks passed (**5 tests / 39 assertions**) with mocked Google token, userinfo, revocation and Meet endpoints. They cover role denial, short-lived one-time OAuth state, encrypted credentials, refresh, room creation, disconnect and unconfigured behavior.
- The complete API suite passed (**168 tests / 1,464 assertions**) on in-memory SQLite and a fresh UTF-8 PostgreSQL 17 cluster; local development data was not used. Google OAuth credentials were not configured, so provider consent and real Meet room creation were not exercised.
- A targeted Chromium UI test passed (**1 test in 9 seconds**) against the running local Vite preview. It verified the connected organizer state, generated URL insertion and scheduling payload; all API calls were mocked. Root `npm run typecheck`, `npm run lint` and `npm run build` passed; the build prerendered 17 public routes. Playwright config also supports `PLAYWRIGHT_BASE_URL` for such externally running local previews. Pint passed for changed PHP files and `git diff --check` passed. Hosted PostgreSQL 16 remains open.

## Exam-series comparison analytics (19 November 2026)

- Exams can optionally share a series name. The report groups published results by series, class, section and subject across exam dates and academic sessions, normalizes scores to each paper maximum, and reports change in percentage points from the previous exam. Teacher comparisons are constrained by exact assignment scope and the API returns no learner identities. The dashboard shows baselines and changes and exports the comparison as CSV.
- Focused AcademicExam tests passed (**6 tests / 117 assertions**) on in-memory SQLite and the full API suite passed (**163 tests / 1,425 assertions**) on both in-memory SQLite and a fresh disposable PostgreSQL 17 cluster. Root TypeScript, ESLint, production build (17 pre-rendered routes), Pint and `git diff --check` passed. The additive migration is applied to local SQLite. The focused Chromium exam-report test verified series baseline/change rows and the series CSV download; the full serial Playwright run passed **15 tests in 2.3 minutes**. Hosted PostgreSQL 16 CI remains unverified.
- A focused test command was initially run without loading the PHPUnit configuration or forcing an in-memory database; local development SQLite was reset by Laravel's test refresh. Its current user and organization counts are zero. The database contains no production data; a local account must be registered again. Future PHP tests in this workspace are run with explicit in-memory SQLite variables.

## Local preview smoke check (19 November 2026)

- The restarted web preview at port 4180 returned HTTP 200 for `/login`; the API readiness endpoint on port 8010 returned `status: ok` with its database check healthy. Local HTTP smoke checks required authorized loopback access; the preview remains a local development service.

## Local development port selection (19 November 2026)

- The combined dev launcher now probes the default API and web ports and selects the next available localhost port when one is occupied; explicit `EDTECHLIVE_API_PORT` and `EDTECHLIVE_WEB_PORT` overrides remain supported. The selected ports are used consistently for the Laravel origin, Vite API proxy, verification links and Sanctum stateful domains. With defaults unavailable, the launcher selected web port 4174 and API port 8003; `/login`, `/workspace/admin`, and API readiness each returned HTTP 200.

## Browser signup verification and sign-in (19 November 2026)

- The isolated Chromium integration flow passed (**1 test in 1.5 minutes**): create an account, open the local signed verification link, sign in, select the new workspace, reset the password, and sign in again. Playwright uses a temporary SQLite database that is removed after the run.

## Exam class-section analytics (19 November 2026)

- Added an identity-free aggregate for each published examination and academic class section. It combines subject papers, normalizes each graded score to that paper's maximum before averaging, and returns learner and result counts plus graded/absent/excused totals. Teachers only see results from their assigned session/section/subject combinations. The dashboard displays and exports the section summary beside paper details.
- AcademicExam tests passed (**5 tests / 96 assertions**) on in-memory SQLite and a fresh temporary PostgreSQL 17 cluster; the full Laravel suite passed on both databases (**162 tests / 1,404 assertions**). The focused Chromium dashboard/export test passed (**1 test**), and the full serial Playwright suite passed (**15 tests in 2.4 minutes**). Root typecheck, ESLint, production build (17 prerendered routes), Pint, and `git diff --check` passed. The disposable PostgreSQL cluster was stopped and removed.

## Mobile examination summaries (19 November 2026)

- Admin and Management mobile overviews now include the same tenant-scoped, identity-free section exam averages and published outcome counts available in the web reports. Mobile requests use the existing bearer token and organization context; no learner-level results are included.
- `npm run typecheck` passed in `apps/mobile`; Expo Android export passed (564 modules, 1.7 MB). The available Node runtime was 20.19.1 while the app declares a minimum of 20.19.4; the local export succeeded, but native device/emulator testing was not performed.

## Mobile finance snapshot (19 November 2026)

- Admin, Management and Independent Educator mobile overviews now display the API's role-checked, read-only aggregate finance totals and default receipt/expense period net cash flow. The view omits fee-level and student-level records.
- `npm run typecheck` passed in `apps/mobile`; Expo Android export passed (564 modules, 1.7 MB). Device/emulator interaction was not performed.

## Mobile teacher school-exam marking (19 November 2026)

- Teachers can open only their assigned school-exam papers, load the active roster, mark graded/absent/excused outcomes, enter paper totals and named paper-section scores, and save draft results. The app validates score caps and section-total consistency before sending; API assignment scope, publication locks, and score validation remain authoritative. Published paper marks are view-only.
- Mobile typecheck passed and Expo Android export passed (564 modules, 1.7 MB). Device/emulator interaction was not available for verification.

## Manual receipt idempotency (19 November 2026)

- Fee receipt POSTs now require a tenant-scoped `Idempotency-Key`. An additive migration adds a unique `(organization_id, idempotency_key)` index and stores a SHA-256 fingerprint of the fee and validated payment payload. Exact retries return the current persisted fee result without another payment notification; a key reused for different payment details or a different fee returns 409.
- The FeeWorkspace creates one UUID per receipt form and reuses it if the request is retried. Focused regression coverage checks missing keys, duplicate receipt IDs and notification count, payload/fee conflicts, organization-level key reuse, and existing overpayment behavior. Focused FeeRecord tests passed (**4 tests / 42 assertions**). Full Laravel suite passed on in-memory SQLite and a fresh disposable PostgreSQL 17 cluster (**161 tests / 1,373 assertions on each**). Root typecheck, ESLint, production build (17 prerendered routes), Pint, and `git diff --check` passed. No production data or database was used.

## Course-specific public enquiry (19 November 2026)

- Published course detail pages now provide an “Ask about this course” CTA. It carries the public course and organization names to the contact page, where the course context, enquiry category, and an editable message are shown before the visitor submits the existing consented contact form.
- The focused Chromium course-discovery workflow passed with the contextual form submission captured and verified. Root typecheck, ESLint, production build and 17-route prerender passed; latest main JavaScript entry is 319.83 kB (gzip 99.73 kB). The complete 14-test serial E2E suite passed immediately before this change; it was not rerun after adding the contextual form.

## Academic master edit forms (19 November 2026)

- Added API-backed edit forms for academic sessions, sections, and subjects alongside the existing class editor. The forms reuse the authorized tenant PATCH routes, keep archive/restore and current-session controls intact, and support cancel/reset and API error feedback. Session end dates are constrained to start dates by the browser input.
- Added focused Laravel regression coverage for all three edit endpoints. `php artisan test --filter=AcademicMasterTest` passed (10 tests / 84 assertions). The full Laravel suite passed (159 tests / 1,351 assertions); web typecheck, ESLint, and production build passed, with 17 prerendered public routes. The full serial Chromium suite passed (14 tests / 2.4 minutes), including live registration, verification, sign-in, password reset and re-login; Admin permission grants; delegated Management read-only access; seven dashboard previews; and 19 responsive routes. An initial full browser run had a post-reset sign-in failure; the auth test passed in isolation and the complete serial rerun passed.
- The full Laravel suite also passed on a fresh disposable PostgreSQL 17 cluster (**159 tests / 1,351 assertions**); all API migrations applied. The cluster ran on a dedicated loopback port and was stopped and removed after the check. The installed PostgreSQL service and its databases were not used.

## Tenant-scoped membership capabilities (19 November 2026)

- Added membership read/manage/audit capability overrides for eligible active memberships, Admin and Independent Educator grant authority, Management read defaults, role-rank limits for delegated managers, implied member-list read when manage/audit is granted, and audited permission before/after values. The responsive dashboard exposes Members & roles to accounts whose active workspace grants membership read and shows an authorization message when audit access is absent.
- Full Laravel suite passed on in-memory SQLite (**159 tests / 1,351 assertions**); the focused membership suite passed (**8 tests / 82 assertions**). Pint, web typecheck, ESLint, and production build passed; the build prerendered 17 public routes. Full Playwright Chromium passed **14 tests in 2.4 minutes** with one worker and the separate preview stopped. Coverage includes real registration, verification, sign-in, password reset and re-login; Admin membership permission grants; delegated Management read-only access without audit permission; notification preference persistence; all seven dashboard previews; and 19 route widths. No PostgreSQL or production deployment check was performed.

## Public course discovery (19 November 2026)

- Added public course listing and detail APIs, default-off organization opt-in, organization-unique slugs, and a privacy-safe response containing published lesson titles only. API regression checks cover editor permissions, draft/publication requirements, disabled/suspended/unlisted courses, filtering, pagination, and private-field exclusion. The organization course editor now enables/disables listing and offers open/copy link actions.
- The full Laravel suite passed: **153 tests / 1,277 assertions** on in-memory SQLite. `vendor/bin/pint --test`, web typecheck, and ESLint passed. The production build passed and prerendered 17 public routes; route-level loading splits reduced the main JavaScript chunk to **319.35 kB** (gzip 99.54 kB).
- Full Playwright Chromium E2E passed: **10 tests** in 1.9 minutes using two workers. The public-catalog browser workflow used a mocked eligible listing and verified search, subject filtering and course details; API privacy/publication behavior was covered by Laravel tests. The current local database migration was applied safely to the ignored SQLite development database. Its public catalog has no eligible courses yet, so it correctly displays the publish-first empty state. No external email or production service was used.

## Dashboard notification preferences (19 November 2026)

- Replaced the sidebar’s preview-only Preferences notice with a connected account settings page. It manages account-scoped in-app categories and email opt-ins when configured, and shows only the signed-in user's recent delivery states for the active organization. The notification bell reuses the same controls; email delivery remains clearly disabled until approved infrastructure is configured.
- The live Chromium account flow now registers, verifies, signs in, selects its organization, opens the dashboard, changes an in-app preference, returns to overview and confirms the saved value after reopening settings. The first run exposed that sidebar actions below the viewport could not be reached; the sidebar now scrolls on short screens.
- Focused authenticated browser test passed (**1 test, 57.7 seconds**) after the sidebar fix. The full Chromium suite then passed (**10 tests, 1.9 minutes**). Web typecheck, ESLint and the production build passed; the build prerendered 17 public routes. API preferences and delivery tests remain covered by the full 153-test Laravel suite recorded above.

## Public website browser smoke suite (19 November 2026)

- Added Playwright Chromium E2E coverage for homepage-to-course search/detail navigation, mobile menu operation and overflow, all 19 public/account routes at 320 px and 1440 px, all seven dashboard previews at 320/768/1440 px, mocked contact-form behavior, mocked auth UI state, and login-page verification resend. A separate live browser test uses a fresh temporary SQLite database and array mailer to register an account, follow the real signed verification link, sign in with a browser session, and reach organization selection.
- Browser suite passed: **10 tests** in Chromium (1.5 minutes, two workers). Web typecheck, ESLint, and production build passed; build prerendered 17 public routes (499.31 kB main JS bundle). The live account test covers registration, signed verification, session login, local password-reset link, password update and re-login using disposable SQLite and an in-memory mailer. The UI regression verifies that an unverified user can request a link from the login page without losing access to the login form. No external email was sent and the temporary database is not persistent. Full authenticated role workflows, full accessibility review and other role workspace operations remain open. CI installs PHP dependencies and Chromium and runs `npm run test:e2e` after the web production build.
- Full Laravel regression passed: **150 tests / 1,225 assertions** on in-memory SQLite. `vendor/bin/pint --test` passed.
- Focused Laravel `AuthenticationTest` passed: **8 tests / 57 assertions** on the PHPUnit in-memory SQLite database. It verifies login is rejected until email verification, local registration/resend returns a clickable signed verification URL, local password reset exposes a clickable URL without sending email, and both flows enable sign-in.

## Opt-in workspace email notifications (19 November 2026)

- Added per-category email preferences separate from in-app notifications. Only verified opted-in members are queued; the feature flag is off by default. Queued messages retry, completed jobs clear message bodies, and recipients can see only their own queued/sent/failed/cancelled status.
- Full Laravel suite passed (**149 tests / 1,215 assertions**) on in-memory SQLite; `vendor/bin/pint --test`, web typecheck, ESLint, and production build passed (14 prerendered routes; 493.63 kB main bundle). Tests cover feature-off behavior, verified opt-in filtering, announcement targeting, queue outcomes, opt-out cancellation, preference validation, and recipient scoping.
- No email provider was configured and no real email was sent. Delivery remains disabled by default pending an approved transport and persistent queue worker. PostgreSQL and deployed queue verification remain open.

## Role dashboard workflow connections (19 November 2026)

- Teacher Submissions now opens the existing API-backed assignment submission and grading workflow; Question bank opens the course-scoped quiz/question-bank manager. Student Lessons & notes opens enrolled lessons, and parent Notices shows a paginated membership-scoped inbox with read receipts.
- Management Attendance now opens the existing organization-scoped attendance report. Removed the static teacher submission badge instead of implying an unverified count.
- Web typecheck, ESLint, and production build passed. The build prerendered 14 public routes and split the inbox into a 3.19 kB JavaScript chunk plus 1.47 kB CSS. No authenticated browser interaction was run; API authorization and read-receipt behavior remain covered by the Laravel suite.

## Manual WhatsApp announcement sharing (19 November 2026)

- Authorized announcement managers can enter a phone number with country code and open WhatsApp with the selected announcement title and body prefilled. The number and message remain in the browser; staff review and send directly in WhatsApp.
- Web typecheck, ESLint, and production build passed. The build prerendered 14 public routes (492.43 kB main bundle). No automated WhatsApp delivery or message logging was added.

## Private meeting recording workflow (19 November 2026)

- Added a tenant-scoped private recording library to scheduled meetings: staff can upload MP4/MOV/WebM files up to 50 MB or register an HTTPS link, attest participant notice and applicable consent, then publish, unpublish, or withdraw recordings. Students and explicitly linked parents see published recordings only for meetings in their authorized classes/courses. Web downloads recheck meeting membership and publication; native clients request two-minute signed links for uploaded files. Withdrawn files remain stored pending retention policy.
- Regression coverage verifies consent and HTTPS requirements, staff publication, student/parent read access, unrelated learner denial, authenticated and signed private file downloads, publication revocation, and withdrawn recording behavior. The additive migration was applied to the verified local SQLite database.
- Full Laravel suite passed (**144 tests / 1,191 assertions**) on in-memory SQLite; Pint passed. Web typecheck and ESLint passed. Mobile typecheck and Android Expo export passed (564 modules, 1.7 MB bundle); native device builds were not run. PostgreSQL verification, authenticated browser QA, consent collection, retention controls, and durable production storage remain open.

## Email verification guidance (19 November 2026)

- Registration and verification-resend success screens now clearly state that verification is required before sign-in. The local verification link is a high-contrast full-width action with explicit steps to verify, return to the app, and log in.
- Web ESLint passed; the production build and TypeScript check passed with 14 prerendered routes (487.30 kB main bundle). Existing API verification remains 142 tests / 1,159 assertions; the preview login page returned HTTP 200.

## Branch assignment to academic records (19 November 2026)

- Added nullable tenant branch references for classes, students, and teachers. Academic class setup and people rosters now assign active same-organization branches, display branch labels, and filter rosters by branch.
- Students inherit their branch from a branch-bound class and follow the class branch when reassigned unless the request explicitly supplies a conflicting branch. Class branch changes are guarded against conflicting student placement and teacher assignments; teaching assignment creation enforces branch compatibility while allowing organization-wide branchless teachers.
- Regression coverage verifies student inheritance and reassignment, explicit mismatch rejection, branch roster filters, safe class branch changes, and branch-specific teacher assignment. The additive migration was applied to local SQLite.
- Student and teacher rosters now expose profile editing alongside add/search/branch filters/status actions; student edits include branch-aware class, section, and session placement.
- Class branch assignments are editable from the Academic Masters class form, with API checks retaining placement consistency.
- Full Laravel suite passed (**142 tests / 1,159 assertions**); Pint, web typecheck, ESLint, and production build passed. The current frontend build prerendered 14 routes (488.27 kB main bundle); the local frontend and API readiness endpoint both returned HTTP 200. PostgreSQL verification remains open.

## Notification preferences and browser preview (19 November 2026)

- Added account-level in-app preferences for announcements, scheduled meetings, and fee notices. All categories default to enabled, and server delivery applies saved preferences; notification fee text avoids exposing fee amounts/titles.
- Preference settings are available from the signed-in notification bell and are validated through authenticated `GET/PATCH /api/v1/me/notification-preferences`. Announcement preference filtering, default values, validation, and persistence are covered by tests.
- Full Laravel suite passed on in-memory SQLite (**132 tests / 1,075 assertions**); Pint, web typecheck, ESLint, and production build passed (14 routes prerendered; 488.56 kB main bundle). Local SQLite migration applied. PostgreSQL verification remains open.
- Restarted the local Vite/Laravel stack with browser-accessible permissions; localhost website and API health returned HTTP 200.

## Branch master (19 November 2026)

- Added organization branches with tenant-unique names/codes, optional address and contact fields, paginated search, active filtering, edit, and activation controls in the Academic Masters dashboard.
- API tests cover management access, defaults, search/status filters, duplicate rejection, role denial, and cross-organization read/update denial. The additive SQLite migration applied successfully.
- Full Laravel suite passed (**134 tests / 1,094 assertions**), Pint passed, and web typecheck/lint/build passed (14 prerendered routes; 494.99 kB main bundle). PostgreSQL verification remains open.

## Fee Category Master (19 November 2026)

- Added tenant-owned fee categories with create/edit/search/active filters and activation controls in Academic Masters. Fee receivables can reference active same-tenant categories; the dashboard requires a category for newly issued fees and linked families see its label. Existing unclassified records remain supported.
- Tests cover category CRUD/filtering, role restrictions, tenant isolation, default activation, linking categories to fees, and rejection of invalid category IDs. The additive migration applied to local SQLite.
- Full Laravel suite passed (**136 tests / 1,116 assertions**); Pint, web typecheck, ESLint, and production build passed (14 prerendered routes; 482.22 kB main bundle). PostgreSQL verification remains open.

## Organization member roles and access audit (19 November 2026)

- Added admin/independent-educator member search, role updates, access suspension/reactivation, and recent access audit history in the Invitations workspace. Pending invites continue through the invitation workflow.
- API tests verify role/status changes, audit attribution, suspended-member restore, admin-only access, self-edit denial, invalid state denial, and cross-tenant record denial. The database transaction keeps an active administrator or independent educator in the organization.
- Full Laravel suite passed (**139 tests / 1,138 assertions**); Pint, web typecheck, ESLint, and production build passed (14 prerendered routes; 483.29 kB main bundle). Local SQLite migration applied. PostgreSQL verification remains open.

## Batch master and local verification recovery (19 November 2026)

- Added tenant-scoped academic batches with create/edit/activate/deactivate, search, session/class/status filters, pagination, section-placement checks, and organization isolation. The admin Academic masters page includes the Batches tab.
- Local registration and resend-verification now return a short-lived signed URL only when `APP_ENV=local`; the browser auth screen displays it as a clickable link. Verification remains required before sign-in, and the test covers register, resend, signed verification, and successful token login. Other environments omit the helper URL.
- Full Laravel suite passed on in-memory SQLite (**130 tests / 1,062 assertions**). Pint, web typecheck, ESLint, and production build passed (14 routes prerendered; 487.24 kB main bundle). The local SQLite migration is applied. PostgreSQL verification for the latest migrations remains open.

## Board-style exam paper sections (19 November 2026)

- Exam papers now accept up to 20 optional named sections. The API enforces that section maximums add up to the paper maximum and that every graded learner has each section score, each within its cap, summing to the saved total. Assigned-teacher scope and published-result locks apply to section marks as they do to the paper.
- The web exam workspace supports section setup, per-section teacher mark entry, and published student/family score breakdowns. Identity-free published reports and CSV exports include per-part averages and graded counts. API coverage checks the valid workflow, missing sections, out-of-range section scores, mismatched totals, published family output and aggregate section analytics.
- Full Laravel suite passed on in-memory SQLite (**127 tests / 1,021 assertions**); Pint, web typecheck, ESLint, and production build passed (14 routes prerendered; 478.51 kB main bundle). The local SQLite migration is applied. PostgreSQL 17 still needs a migration/test run for the new tables.

## PostgreSQL compatibility recheck (19 November 2026)

- A temporary local PostgreSQL 17 cluster applied every API migration, then the complete Laravel suite passed (**113 tests / 820 assertions**). The same full suite passed on in-memory SQLite.
- Cross-database execution found that notification organization scope used a JSON path operator unsupported for Laravel's text-backed PostgreSQL notification payload. Replacing it with Laravel's driver-aware `whereJsonContains` resolved the issue; notification and announcement tests passed on both engines.
- `vendor/bin/pint --test` passed. The PostgreSQL 16 GitHub Actions job is configured but has not yet had a hosted run. The disposable local cluster is not a deployment database.

## Mobile Admin/Management summaries and reports (19 November 2026)

- Admin and Management now load five active students and five active teachers, along with total active roster counts, from their authenticated organization endpoints. They also see today's attendance totals and section breakdowns using the organization's timezone. The API remains the source of role authorization and tenant scoping.
- Added an aggregate course-progress view with overall average completion and the top five published courses; it excludes learner identities and uses the existing role-authorized report API.
- Mobile TypeScript passed and Expo Android export bundled 564 modules into a 1.7 MB Hermes bundle. Native device verification and other mobile reporting/approval flows remain open.

## Preferred organization persistence (19 November 2026)

- Added a nullable user preference with a foreign key that clears on organization deletion, a membership-checked save endpoint, and restore-on-reload behavior in the web client. The API reports a saved choice only while its membership and organization remain active.
- The full API suite passed (**107 tests / 757 assertions**) with in-memory SQLite; `vendor/bin/pint --test`, web typecheck, and ESLint passed. The migration applied to the isolated local SQLite database; production build passed with 14 prerendered routes and a 490.99 kB main JavaScript bundle.
- The Expo client now reads the account preference on restore/sign-in and saves changes through the same membership-checked endpoint. Mobile TypeScript passed and Android export bundled 564 modules (2.5 MB JavaScript payload); native device verification remains open.

## Allowlisted platform overview (19 November 2026)

- Added an explicit `PLATFORM_ADMIN_EMAILS` allowlist, separate from organization roles and enquiry inbox access, and a read-only platform API with organization/user/educator/open-enquiry counts and recent organization metadata. The verified operator can open the live overview directly after sign-in.
- API coverage confirms accurate aggregates and denies non-allowlisted accounts. Full Laravel suite passed (**111 tests / 786 assertions**) with Pint; web typecheck, ESLint, and production build passed (14 routes prerendered, 483.64 kB main bundle).
- Added a read-only searchable/status-filtered platform organization directory with aggregate roster counts. API coverage verifies filters, roster totals, and non-allowlisted denial; full API and web checks passed.
- Added an allowlisted, read-only account directory with name/email search, active role and email-verification filters, pagination and a minimal response that omits secrets. Tests verify filter behavior and non-allowlisted denial. The Super Admin Educators destination now opens the same directory prefiltered for independent educators. Full Laravel suite passed (**113 tests / 809 assertions**) with Pint; root typecheck, ESLint, and production build passed (14 routes prerendered, 485.16 kB main bundle).

## Organization settings (19 November 2026)

- Connected Admin and independent-educator Settings pages to a role-checked API for organization name and timezone updates; management users and other roles are denied, and invalid/empty settings are rejected.
- Full Laravel suite passed (**112 tests / 794 assertions**) with Pint; web typecheck, ESLint and production build passed (14 routes prerendered, 484.24 kB main bundle).

## Scoped document library (19 November 2026)

- Added a paginated organization document endpoint and role-based web views. Administrators and management see tenant files, teachers see their course files, and students see only published files for actively enrolled courses.
- Full Laravel suite passed (**109 tests / 767 assertions**) with in-memory SQLite and Pint; root typecheck, ESLint, and production build passed. The build prerendered 14 public routes and emitted a 481.79 kB main JavaScript bundle plus separate lazy-loaded document-library and management-roster chunks.
- Parents now see published study materials only for active children connected through an explicit guardian link and active enrollment. Parent download and unrelated-course denial are covered. Full API regression passed (**112 tests / 799 assertions**), as did Pint, root TypeScript, ESLint, and production build (14 routes; 484.47 kB main bundle).
- Mobile student/parent dashboards now load that same role-scoped library. The API issues a two-minute signed, relative download URL only after rechecking membership, enrollment/guardian scope and publication; expiry, tampered links and unlinked learners are denied. Full API regression passed (**113 tests / 820 assertions**) with Pint; mobile TypeScript and Android export (564 modules, 2.5 MB JavaScript bundle) passed. Native device download/open behavior remains to verify.
- The management roster route now switches between student and teacher records using the existing management-authorized APIs. Web typecheck, ESLint, and production build passed after the dashboard connection.
- Independent educator course, lesson, learner, assessment and meeting destinations now use the existing tenant-scoped APIs; management Performance uses aggregate assessment outcomes. Web typecheck, ESLint, and production build passed (482.45 kB main bundle; 14 routes prerendered).

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
- Mobile family homework milestone: students and parents can review published assignment due dates, status, scores and feedback through the shared scoped endpoints; no written answer is returned. Mobile TypeScript, Android JavaScript export (564 modules), and root ESLint passed. Native/device builds remain unverified.
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

## Bank statement reconciliation (19 November 2026)

- Added tenant-scoped CSV statement import (maximum 500 rows), duplicate-content protection, opening/closing balance validation, exact-amount and seven-day transaction matching against fee receipts or paid expenses, and reversible one-to-one matches. Candidate lookup excludes already reconciled ledger entries; student, parent and teacher roles are denied.
- Full API suite passed (**102 tests / 705 assertions**) using in-memory SQLite; Pint passed. Reconciliation migrations applied to the ignored local SQLite development database through `npm run setup:local`.
- Root `npm run typecheck`, `npm run lint`, and `npm run build` passed. Build prerendered 14 public routes; the reconciliation UI is a lazy-loaded chunk and the main bundle remains below the 500 kB warning threshold (488.00 kB).
- PostgreSQL CI execution and visual browser review remain open.

## Institution examination results (19 November 2026)

- Added tenant-scoped exam schedules and subject papers per section, assigned-teacher result entry, score and roster validation, absent/excused outcomes, manager publication requiring a result for every active student, and locked post-publication marks.
- Student and explicitly linked parent views receive published school exam results only. Web family results and the Expo assessment history now include school marks; admin/management can schedule and publish, and teachers can grade only assigned section/session/subjects.
- Added an identity-free published-exam report by session, exam, section and subject; exact teacher assignment scope is enforced. Coverage checks graded/absent totals and average marks.
- Full API suite passed (**105 tests / 750 assertions**) with in-memory SQLite; `vendor/bin/pint --test` passed, and the exam migration applied to local SQLite. Teacher tests verify unrelated subject papers are omitted from both paper lists and the aggregate report.
- Root TypeScript, ESLint, production build (14 prerendered routes; 490.62 kB main JS), mobile TypeScript, and Android JavaScript export (564 modules) passed. Native device verification and PostgreSQL CI remain open.

## Academic report CSV export (19 November 2026)

- Added browser CSV export for attendance by class/section and date range, course progress, enrollment by session/section, teacher workload, quiz/assignment outcomes, published school exam performance, and all-time plus selected-period finance summaries. CSV values are quoted and spreadsheet formula prefixes are neutralized; rows reflect the data already scoped for the report view.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed. Production build prerendered 14 public routes and produced a 487.00 kB main JavaScript bundle. Lint initially identified a disallowed control-character regex; the implementation was adjusted and the final lint run passed.
- Browser download behavior has not been visually exercised in an authenticated browser in this environment.

## Platform organization controls and status audit (19 November 2026)

- Added allowlisted, verified-operator API controls to suspend/reactivate an organization, requiring a reason and writing an audit event in the same transaction. Suspended organizations disappear from member organization selection and organization access; reactivation restores access.
- Added a paginated operator-only audit API and dashboard history panel. History contains the organization snapshot, action, reason, before/after status, and operator name/email, with no credentials or tokens.
- Full Laravel suite: **115 tests / 847 assertions** passed on in-memory SQLite. Focused platform suite: **5 tests / 56 assertions**. `vendor/bin/pint --test`, web typecheck, ESLint, and production build passed; the build prerendered 14 public routes with a 487.00 kB main JavaScript bundle.
- The sandboxed PHP runtime could not read workspace paths for PHPUnit bootstrap checks; tests were run with local filesystem access. No production database was changed.
- PostgreSQL 17 revalidation passed the full current suite: **115 tests / 847 assertions**. All migrations, including the platform audit table, applied successfully. The server was bound to loopback on a dedicated port, then stopped; only the disposable system-temp cluster and log were removed. Hosted PostgreSQL 16 CI remains pending.

## Paginated quiz result history (19 November 2026)

- `GET /quizzes/{quiz}/attempts` now returns stable newest-first pages (default 20, maximum 100) with current/last page and total metadata. Student access remains limited to the student's own submitted attempts; teachers/managers see roster identities only for courses they can access. The web quiz workspace provides page navigation.
- Regression tests cover page boundaries, total counts, staff identity visibility, student privacy, and invalid page sizes. Full Laravel suite: **116 tests / 867 assertions** passed on in-memory SQLite and disposable PostgreSQL 17; Pint passed.
- Root typecheck, ESLint, and production build passed. The build prerendered 14 routes with a 488.12 kB main JavaScript bundle. No migration was required.

## Local authentication mail guidance (19 November 2026)

- Earlier local auth success messages directed users to Laravel's log mailer. This behavior has since been replaced for local registration/resend-verification and password recovery by clickable links shown in the auth screen; production still uses configured email delivery.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed. This is presentation/documentation only; no email was sent and no account data was created. The production build prerendered 14 routes and produced a 488.13 kB main JavaScript bundle.

## Contact enquiry replies (19 November 2026)

- Added a verified allowlisted operator reply endpoint and inbox composer. Replies go to the stored enquiry email through Laravel's configured mail transport; only responder/time metadata is saved, not the reply body. Transport exceptions return 503 without marking a reply.
- Focused API suite: **7 tests / 31 assertions** passed. Full API suite passed on in-memory SQLite and a fresh disposable PostgreSQL 17 cluster: **119 tests / 884 assertions** on each. Pint, web typecheck, ESLint and production build passed; 14 public routes prerendered and the main web bundle is 489.52 kB.
- The additive response metadata migration was applied to the isolated local SQLite development database. No real email was sent in verification; outbound production delivery still depends on approved mail configuration.

## Scheduled online objective quizzes (19 November 2026)

- Added single-choice and multiple-select questions with easy/medium/hard difficulty, exact-set server-side scoring, optional opening/closing timestamps, and schedule enforcement alongside existing attempt/time limits. The web and Expo clients support multi-select input and display schedule availability; answer keys remain private.
- PostgreSQL 17 testing exposed a timestamp offset when the database session inherited the host timezone. Laravel's PostgreSQL connector now explicitly sets the session to UTC. Focused quiz tests passed after the fix (**5 tests / 84 assertions**); complete suite passed on SQLite and disposable PostgreSQL 17 (**121 tests / 917 assertions** each).
- `npm run typecheck`, `npm run lint`, `npm run build`, `vendor/bin/pint --test`, mobile TypeScript, and Android export passed. The web build prerendered 14 routes with a 492.35 kB main bundle; Expo bundled 564 modules into a 2.5 MB Android JavaScript bundle. The quiz migration was applied to the isolated local SQLite database.
- Board-style exams, device/browser QA and native builds remain open.

## Course-scoped reusable question banks (19 November 2026)

- Course editors can create named banks, add single-choice or multiple-select questions, search and paginate bank contents, and snapshot a bank question into a draft quiz. Server authorization constrains bank operations and imports to the editor's course; duplicate and cross-course imports are rejected. Student quiz responses continue to omit correct answers.
- Editors can rename/remove banks and update/remove bank questions. Snapshot imports keep their copied quiz content and do not change when the source bank question is edited or deleted. Course authorization, student denial, duplicate import and cross-course protections are covered.
- Focused bank tests passed (**3 tests / 38 assertions**). The complete suite passed on in-memory SQLite and a fresh disposable PostgreSQL 17 cluster (**124 tests / 955 assertions** each); Pint passed.
- Web typecheck, ESLint, and production build passed. The build prerendered 14 public routes; the main JavaScript bundle is 478.15 kB and the lazy-loaded quiz workspace is 47.64 kB. The bank migration was applied to isolated local SQLite through guarded setup.
- Detailed bank change history, device/browser QA and native builds remain open. No authenticated browser visual review was performed.

## Written-response quiz review (19 November 2026)

- Added teacher-reviewed written-response questions to course quizzes and question banks. Students submit up to 10,000 characters; objective portions are scored immediately, while the API marks written submissions pending or partial until the course editor grades each answered response. Grades are capped at question points and support feedback; review metadata includes the grader and timestamp, while responses expose only points/feedback.
- Web and Expo students can answer written questions; course editors review answers in the web quiz workspace. Student and linked-parent result views show pending/reviewed status. Aggregate reports count pending reviews and exclude pending/partial scores from averages.
- Focused subjective, family privacy and assessment aggregate tests passed (**3 tests / 53 assertions**). Full API suite passed on SQLite and disposable PostgreSQL 17 (**125 tests / 983 assertions** each); Pint passed.
- Web typecheck, ESLint and production build passed (14 public routes; 478.34 kB main bundle, 56.00 kB lazy quiz workspace). Expo typecheck and Android export passed (564 modules; 1.7 MB bundle). Local migration was applied with guarded `npm run setup:local`.
- Authenticated visual browser and native-device review remain open.

## Exam-style quiz scoring controls (19 November 2026)

- Draft quizzes can set a negative-marking multiplier from 0 to 1 and an optional pass percentage. Wrong answered objective questions deduct points multiplied by the configured rate; unanswered questions are not penalized and total scores cannot go below zero. Pass/fail remains pending while submitted written responses await review.
- Focused scoring, written-review, and family-result checks passed. Complete API regression passed on SQLite and disposable PostgreSQL 17 (**126 tests / 1,001 assertions** each); Pint passed.
- Web typecheck, ESLint and production build passed (14 prerendered routes; 478.51 kB main bundle, 57.88 kB lazy quiz workspace). Expo typecheck and Android export passed (564 modules; 1.7 MB bundle). The scoring migration was applied through guarded local setup.
- Board-specific paper sections, exam series and section-level analytics remain open.

## Public course detail pages (19 November 2026)

- The home and course catalog cards now link to three explicit sample course details with a sample syllabus, educator-profile preview, level/subject/lesson summary, and enquiry CTA. Copy states that listings are illustrative and enrollment is not available.
- `npm run typecheck`, `npm run lint`, and `npm run build` passed. Build prerendered 17 public routes, including all three course details, with route-specific title/description metadata. The main JavaScript bundle is 498.25 kB; live course discovery and enrollment remain unimplemented pending API-backed course publishing.
- Parsed public/sitemap.xml successfully and confirmed all three sample detail URLs are listed. The sitemap still uses the reserved .example host pending a production domain.
## Super Admin organization status audit view (19 November 2026)

- The Audit logs navigation opens a dedicated paginated view for organization suspension/reactivation events. The screen includes event time, organization, status transition, operator and required reason, and labels the current limited audit scope.
- The existing Laravel platform-audit authorization/history tests passed (**5 tests / 56 assertions**). Web typecheck, ESLint and production build passed; 17 public routes prerendered and the audit page is a 3.24 kB lazy-loaded chunk. Broad platform event auditing remains unimplemented.
## Super Admin platform snapshot report (19 November 2026)

- Platform reports now consumes the existing allowlisted aggregate API and exports a five-column CSV containing current organization/user/educator/enquiry totals and the eight latest organizations. The screen explains the snapshot-only scope and excludes learner-level records.
- Web typecheck, ESLint and production build passed; 17 public routes prerendered. The report component is a 4.56 kB lazy-loaded JavaScript chunk with 0.51 kB CSS. The underlying allowlist and aggregate endpoint are covered by the existing platform feature tests.
