# edTechLive

**Learn. Teach. Manage. Grow.**

An education ERP and learning platform for institutions, independent educators, students and families. This repository contains the public website, role-based dashboards, a Laravel API foundation, connected account flows, and the first persistent academic setup workflows.

## Current status

- Responsive public website with homepage, about, feature catalog, API-backed public course discovery (with clearly identified sample homepage cards), institution/teacher/student solution pages, provisional pricing, connected contact enquiries, authentication screens and draft legal pages.
- Responsive role dashboards at `/workspace/:role`: `super-admin`, `admin`, `management`, `teacher`, `student`, `parent`, `educator`.
- Course search and education-level filtering work locally.
- The public course catalog reads opted-in published courses from the API. Verified students with an active roster and membership at that organization can self-enroll; public sign-up and guest enrollment are not enabled.
- Contact form saves consented enquiries to a throttled API. An authenticated platform inbox filters and tracks status for allowlisted operators; optional email requires `ENQUIRY_RECIPIENT` and a configured mail transport.
- Fee records and categories are persisted by organization; authorized staff can manage categories, create receivables and record receipts. Students and explicitly linked parents can review fees and, only when an operator configures Razorpay Test Mode, pay an outstanding fee through server-verified checkout. Live payments and refunds are not enabled; provider credentials and a real sandbox transaction have not been verified.
- Class and management meetings can be scheduled with scoped HTTPS links. A scheduled Laravel task sends participants one preference-aware reminder before each meeting (15 minutes by default). Optional Google Meet room creation is available when the API operator configures OAuth credentials; participant invitations and attendance sync are not connected.
- Login, organization registration, email verification, forgot/reset password, session persistence and organization selection use Laravel API endpoints. Browser sessions use Sanctum cookies; native clients can request API tokens.
- Example course listings and prices are not real offers. Paid plans are disabled.
- Public routes are statically pre-rendered with route-specific title and description metadata at build time.
- Dashboard pages show the connected account and selected organization when signed in. Admin/management roster, timetable and attendance summaries, independent educator course metrics, teacher assignment/review summaries, student learning summaries, linked-parent summaries, seven-day lesson/assignment activity, and upcoming meetings are live. Upcoming staff timetable classes and student/parent notification panels are also live; platform super-admin metrics remain illustrative.
- Admins can manage academic masters and rosters, invite staff/students/parents, assign teachers, record attendance, schedule weekly classes, view attendance summaries, and create courses, ordered modules, lessons, private study materials, assignments and enrollments. Teachers author course content, reuse course-scoped question-bank items, and grade submissions; enrolled students can follow modular lessons, download published course materials, submit work and take timed, scheduled single-choice or multiple-select quizzes with server-side scoring. These workflows persist through tenant-scoped APIs.
- Admins, management and independent educators can review attendance, enrollment placement and course-progress summaries from the dashboard reports area.
- Laravel 13 API lives in `apps/api`: Sanctum auth/recovery and invitation endpoints, organization/membership, academic masters, roster, teaching assignment, attendance, timetable, family access, attendance reports, courses/modules/lessons, private assets, enrollments, assignments, submissions, grading, scheduled quizzes, in-app notifications and opt-in queued workspace email with delivery status. Workspace email is disabled by default and requires an approved mail transport and queue worker. Production delivery, durable object storage and deployment have not been validated. The latest full API regression passes on in-memory SQLite; the last full PostgreSQL 17 run predates student AI, push, private storage and meeting-reminder changes. Hosted PostgreSQL 16 CI remains unverified.

## Run locally

Requirements: Node.js 20.19.4+ (or 22.13.0+), npm, PHP 8.3+, Composer 2.x, and the PHP extensions in `apps/api/composer.json`.

The account screens need both the Laravel API and Vite frontend. Install the web and API dependencies once:

```powershell
npm install
cd apps/api
composer install
cd ../..
```

Prepare the local API database and start both services from the repository root:

```powershell
npm run setup:local
npm run dev:stack
```

Open the website URL printed by the launcher (defaults: [http://127.0.0.1:4173](http://127.0.0.1:4173)). It starts Laravel on port 8001, the Laravel scheduler, and Vite on port 4173, and stops them when you press Ctrl+C. If a default port is unavailable, the launcher selects the next available local port and prints the resulting URLs. Set `EDTECHLIVE_API_PORT` or `EDTECHLIVE_WEB_PORT` to choose a fixed port; the API proxy, local account links and Sanctum session allowlist follow the selected ports. Keep this terminal open while using the site; if the browser shows a connection timeout, restart `npm run dev:stack` and open the new website URL printed there.

`setup:local` creates `apps/api/.env` only when it is missing, then uses the isolated `apps/api/database/local-dev.sqlite` file, generates an app key if needed, and applies outstanding local migrations. It refuses non-local or non-SQLite database settings and never runs migrations against a server database. If an existing `.env` needs adjustment, keep `APP_ENV=local`, `DB_CONNECTION=sqlite`, `DB_DATABASE=database/local-dev.sqlite`, and leave `DB_URL` empty. Local signup and verification-resend screens show an in-app **Verify email** action that confirms the signed link without an email inbox; password-recovery responses show a local reset link. These development links are available only under `APP_ENV=local` and do not send email. Production inbox delivery requires a configured mail transport.

Optional educator AI drafts are disabled by default. For a local-only trial, run Ollama on the API host, install a model there, then set `AI_DRIVER=ollama`, `AI_BASE_URL=http://127.0.0.1:11434`, and `AI_MODEL` to the installed model name in `apps/api/.env`, and restart Laravel. The course editor then offers teacher-reviewed lesson-plan and multiple-choice question drafts in English or Hindi. No learner records are sent, and prompts/drafts are not stored. See [Ollama's local generate API](https://docs.ollama.com/api/generate). Do not point this setting at an external paid endpoint.

The root `.env.example` configures Vite to proxy `/api` and `/sanctum` to Laravel. The API's `.env.example` documents `FRONTEND_URL`, CORS origins and Sanctum stateful domains.

## Google Meet organizer setup

The Google Meet connection controls appear in the signed-in **Admin**, **Management**, **Teacher**, or **Independent Educator** workspace under **Meetings**, **Management meetings**, **Live classes**, or **Class schedule**, respectively. Students and parents only see meetings they are allowed to join; they do not connect an organizer account. If Google Meet is not configured, staff can still schedule a meeting by pasting an HTTPS link created manually.

To enable room creation, create an OAuth web client in Google Cloud, enable the Google Meet REST API, and register the exact API callback URL as an authorized redirect URI. Set these values in `apps/api/.env` (never commit the client secret):

```dotenv
GOOGLE_MEET_CLIENT_ID=your-oauth-client-id
GOOGLE_MEET_CLIENT_SECRET=your-oauth-client-secret
GOOGLE_MEET_REDIRECT_URI=https://your-api-origin/api/v1/integrations/google-meet/callback
FRONTEND_URL=https://your-web-origin
```

For local development, replace the origins with the API and website URLs printed by `npm run dev:stack`; the redirect URI must match the Google Cloud entry exactly, including scheme, host, port, and path. Restart Laravel after changing the environment. Staff then open the scheduling screen, choose **Connect Google**, authorize their own organizer account, and use **Create Meet link**. Connections are per user and organization. The app requests the `meetings.space.created` scope and creates rooms only; it does not invite participants, capture meeting streams, or sync attendance. Meeting reminders use the Laravel scheduler and default to 15 minutes before start; configure `MEETING_REMINDER_MINUTES` to change the window. Local `dev:stack` runs the scheduler, and deployments must run `php artisan schedule:run` every minute. If OAuth is unavailable, the manual HTTPS link field continues to work.

Contact-enquiry retention is unset and disabled by default. After an approved retention period is chosen, `php artisan contact-enquiries:purge` reports eligible records without deleting them; a manual purge requires `--force` plus `CONTACT_ENQUIRY_RETENTION_ENABLED=true`. The Laravel daily schedule also remains gated by that explicit setting.

## Mobile app

The Expo client setup and role/workflow coverage are documented in [`apps/mobile/README.md`](apps/mobile/README.md). For Android emulator development, point `EXPO_PUBLIC_API_URL` at `http://10.0.2.2:8001`; physical devices need the development computer's LAN address and an API server bound to its network interface. Native Android/iOS builds still require the respective platform toolchains.

## Checks

```sh
npm run typecheck
npm run test:unit
npm run lint
npm run build
npm run test:e2e
```

The Playwright suite starts Vite and Laravel on ports 4188/4189 and uses an isolated, temporary SQLite database with an in-memory mailer; it removes the temporary data when the run ends. Install the browser once with `npx playwright install chromium` before running it locally; CI installs PHP dependencies and Chromium automatically. The suite includes mocked published-course listing/filter/detail behavior, a real local registration, signed verification, session login and workspace-selection flow. Contact-form requests and a separate UI regression case use mocked responses; no email is delivered.

`npm run test:unit` runs the React component suite with Vitest, jsdom, and React Testing Library. It currently verifies account registration with a local verification action and the unverified-account login recovery flow.

To run a mocked UI test against an already-running local Vite preview, set `PLAYWRIGHT_BASE_URL` to its origin (for example `http://127.0.0.1:4180`). This skips the disposable test server; API calls in the targeted Google Meet UI test are intercepted by Playwright.

## Technology

React, TypeScript, Vite, React Router and Lucide icons. Styling uses project-local responsive design systems in `src/styles.css` and `src/dashboard.css`.

## Project records

See `docs/` for client requirements, BRD, SRS, feature priorities, architecture, database direction, API plan, roadmap, implementation status, security, deployment, test report and known issues. Legal pages are working drafts and require qualified review before launch.
