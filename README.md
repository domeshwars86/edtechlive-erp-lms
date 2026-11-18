# edTechLive

**Learn. Teach. Manage. Grow.**

An education ERP and learning platform for institutions, independent educators, students and families. This repository contains the public website, role-based dashboards, a Laravel API foundation, connected account flows, and the first persistent academic setup workflows.

## Current status

- Responsive public website with homepage, about, feature catalog, sample course discovery, institution/teacher/student solution pages, provisional pricing, connected contact enquiries, authentication screens and draft legal pages.
- Responsive role dashboards at `/workspace/:role`: `super-admin`, `admin`, `management`, `teacher`, `student`, `parent`, `educator`.
- Course search and education-level filtering work locally.
- Contact form saves consented enquiries to a throttled API. An authenticated platform inbox filters and tracks status for allowlisted operators; optional email requires `ENQUIRY_RECIPIENT` and a configured mail transport.
- Fee records are persisted by organization; authorized staff can create receivables and record already-received partial/final payments, while students and linked parents have read-only access. No payment is initiated by the app.
- Class and management meetings can be scheduled with scoped HTTPS links; provider room creation and reminders are not connected yet.
- Login, organization registration, email verification, forgot/reset password, session persistence and organization selection use Laravel API endpoints. Browser sessions use Sanctum cookies; native clients can request API tokens.
- Example course listings and prices are not real offers. Paid plans are disabled.
- Public routes are statically pre-rendered with route-specific title and description metadata at build time.
- Dashboard pages show the connected account and selected organization when signed in. Admin/management roster, timetable and attendance summaries, independent educator course metrics, teacher assignment/review summaries, student learning summaries, linked-parent summaries, seven-day lesson/assignment activity, and upcoming meetings are live. Upcoming staff timetable classes and student/parent notification panels are also live; platform super-admin metrics remain illustrative.
- Admins can manage academic masters and rosters, invite staff/students/parents, assign teachers, record attendance, schedule weekly classes, view attendance summaries, and create courses, ordered modules, lessons, private study materials, assignments and enrollments. Teachers author course content and grade submissions; enrolled students can follow modular lessons, download published course materials, submit work and take timed, auto-scored quizzes. These workflows persist through tenant-scoped APIs.
- Admins, management and independent educators can review attendance, enrollment placement and course-progress summaries from the dashboard reports area.
- Laravel 13 API lives in `apps/api`: Sanctum auth/recovery and invitation endpoints, organization/membership, academic masters, roster, teaching assignment, attendance, timetable, family access, attendance reports, courses/modules/lessons, private assets, enrollments, assignments, submissions, grading, quizzes and attempts. Local development uses an ignored SQLite database and private local file storage; production email delivery, durable object storage, PostgreSQL migrations and deployment have not been validated.

## Run locally

Requirements: Node.js 20.19.4+ (or 22.13.0+), npm, PHP 8.3+, Composer 2.x, and the PHP extensions in `apps/api/composer.json`.

The account screens need both the Laravel API and the Vite frontend running. On a fresh checkout, prepare the API first in PowerShell:

```powershell
cd apps/api
composer install
Copy-Item .env.example .env
```

Edit `apps/api/.env` for local development: set `DB_CONNECTION=sqlite`, `DB_DATABASE=database/local-dev.sqlite`, `SESSION_DRIVER=file`, `CACHE_STORE=file`, and `QUEUE_CONNECTION=sync`. Then create the local database, generate its app key and run migrations:

```powershell
New-Item -ItemType File database/local-dev.sqlite -Force
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8001
```

In a second terminal, from the repository root, install and start the web frontend:

```powershell
npm install
npm run dev -- --host 127.0.0.1 --port 4173
```

Open [http://127.0.0.1:4173](http://127.0.0.1:4173). Vite proxies `/api` and `/sanctum` to `http://127.0.0.1:8001`; set `VITE_API_PROXY_TARGET` only when using a different API port. Verification and password reset messages use Laravel's configured mail transport; the default local log transport records links in `apps/api/storage/logs/laravel.log`.

The root `.env.example` configures Vite to proxy `/api` and `/sanctum` to Laravel. The API's `.env.example` documents `FRONTEND_URL`, CORS origins and Sanctum stateful domains.

## Mobile app

The Expo client setup and role/workflow coverage are documented in [`apps/mobile/README.md`](apps/mobile/README.md). For Android emulator development, point `EXPO_PUBLIC_API_URL` at `http://10.0.2.2:8001`; physical devices need the development computer's LAN address and an API server bound to its network interface. Native Android/iOS builds still require the respective platform toolchains.

## Checks

```sh
npm run typecheck
npm run lint
npm run build
```

## Technology

React, TypeScript, Vite, React Router and Lucide icons. Styling uses project-local responsive design systems in `src/styles.css` and `src/dashboard.css`.

## Project records

See `docs/` for client requirements, BRD, SRS, feature priorities, architecture, database direction, API plan, roadmap, implementation status, security, deployment, test report and known issues. Legal pages are working drafts and require qualified review before launch.
