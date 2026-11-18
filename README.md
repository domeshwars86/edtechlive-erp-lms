# edTechLive

**Learn. Teach. Manage. Grow.**

An education ERP and learning platform for institutions, independent educators, students and families. This repository contains the public website, role-based dashboards, a Laravel API foundation, connected account flows, and the first persistent academic setup workflows.

## Current status

- Responsive public website with homepage, about, feature catalog, sample course discovery, institution/teacher/student solution pages, provisional pricing, connected contact enquiries, authentication screens and draft legal pages.
- Responsive role dashboards at `/workspace/:role`: `super-admin`, `admin`, `management`, `teacher`, `student`, `parent`, `educator`.
- Course search and education-level filtering work locally.
- Contact form sends consented enquiries to a throttled API and persists them; optional inbox email requires `ENQUIRY_RECIPIENT` and a configured mail transport. Staff review UI remains to be built.
- Fee records are persisted by organization; authorized staff can create receivables and record already-received partial/final payments, while students and linked parents have read-only access. No payment is initiated by the app.
- Class and management meetings can be scheduled with scoped HTTPS links; provider room creation and reminders are not connected yet.
- Login, organization registration, email verification, forgot/reset password, session persistence and organization selection use Laravel API endpoints. Browser sessions use Sanctum cookies; native clients can request API tokens.
- Example course listings and prices are not real offers. Paid plans are disabled.
- Public routes are statically pre-rendered with route-specific title and description metadata at build time.
- Dashboard pages show the connected account and selected organization when signed in. Admin/management roster, timetable and attendance summaries, independent educator course metrics, teacher assignment/review summaries, student learning summaries, and linked-parent summaries are live; other overview panels and the platform super-admin preview remain illustrative content.
- Admins can manage academic masters and rosters, invite staff/students/parents, assign teachers, record attendance, schedule weekly classes, view attendance summaries, and create courses, lessons, private study materials, assignments and enrollments. Teachers author their course content and grade submissions; enrolled students can download published course materials, submit work and take timed, auto-scored quizzes. These workflows persist through tenant-scoped APIs.
- Laravel 13 API lives in `apps/api`: Sanctum auth/recovery and invitation endpoints, organization/membership, academic masters, roster, teaching assignment, attendance, timetable, family access, attendance reports, courses, lessons, private assets, enrollments, assignments, submissions, grading, quizzes and attempts. Local development uses an ignored SQLite database and private local file storage; production email delivery, durable object storage, PostgreSQL migrations and deployment have not been validated.

## Run locally

Requirements: Node.js 20+ and npm.

```sh
npm install
npm run dev
```

Backend setup is documented in [apps/api/README.md](apps/api/README.md). For local development, point `VITE_API_PROXY_TARGET` at the Laravel port; production must use PostgreSQL and a configured mail provider.

The root `.env.example` configures Vite to proxy `/api` and `/sanctum` to Laravel. The API's `.env.example` documents `FRONTEND_URL`, CORS origins and Sanctum stateful domains.

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
