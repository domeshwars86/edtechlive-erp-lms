# edTechLive

**Learn. Teach. Manage. Grow.**

An education ERP and learning platform for institutions, independent educators, students and families. This repository contains the public website, role-based dashboards, a Laravel API foundation, connected account flows, and the first persistent academic setup workflows.

## Current status

- Responsive public website with homepage, about, feature catalog, sample course discovery, institution/teacher/student solution pages, provisional pricing, contact form demonstration, authentication screens and draft legal pages.
- Responsive role dashboards at `/workspace/:role`: `super-admin`, `admin`, `management`, `teacher`, `student`, `parent`, `educator`.
- Course search and education-level filtering work locally.
- Contact form performs browser-side required-field and format validation, then displays an explicit preview-only success state. It does not transmit data.
- Login, organization registration, email verification, forgot/reset password, session persistence and organization selection use Laravel API endpoints. Browser sessions use Sanctum cookies; native clients can request API tokens.
- Example course listings and prices are not real offers. Paid plans are disabled.
- Public routes are statically pre-rendered with route-specific title and description metadata at build time.
- Dashboard pages show the connected account and selected organization when signed in, while all activity, schedule and record data remains clearly labelled illustrative content. Unauthenticated role previews remain available for demonstration.
- Admins can manage academic masters and student/teacher rosters, invite organization members, assign teachers to a subject and section for an academic session, record paginated daily attendance, and schedule weekly class sessions. Teachers accept invitations into linked profiles and can manage attendance only for assigned sections. These records persist through tenant-scoped APIs.
- Laravel 13 API lives in `apps/api`: Sanctum auth/recovery and invitation endpoints, organization/membership APIs, and tenant-scoped academic master, roster, teaching assignment, attendance, and timetable APIs. Local development uses an ignored SQLite database; production email delivery and PostgreSQL migrations have not been validated.

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
