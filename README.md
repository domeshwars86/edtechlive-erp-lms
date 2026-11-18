# edTechLive

**Learn. Teach. Manage. Grow.**

An education ERP and learning platform for institutions, independent educators, students and families. This repository contains the public website, role-based dashboard previews and an initial Laravel API/tenancy foundation with connected account flows.

## Current status

- Responsive public website with homepage, about, feature catalog, sample course discovery, institution/teacher/student solution pages, provisional pricing, contact form demonstration, authentication screens and draft legal pages.
- Responsive role dashboards at `/workspace/:role`: `super-admin`, `admin`, `management`, `teacher`, `student`, `parent`, `educator`.
- Course search and education-level filtering work locally.
- Contact form performs browser-side required-field and format validation, then displays an explicit preview-only success state. It does not transmit data.
- Login, organization registration, email verification, forgot/reset password, session persistence and organization selection use Laravel API endpoints. Browser sessions use Sanctum cookies; native clients can request API tokens.
- Example course listings and prices are not real offers. Paid plans are disabled.
- Public routes are statically pre-rendered with route-specific title and description metadata at build time.
- Dashboard pages show the connected account and selected organization when signed in, while all activity, schedule and record data remains clearly labelled illustrative content. Unauthenticated role previews remain available for demonstration.
- Laravel 13 API foundation lives in `apps/api`: Sanctum auth/recovery endpoints, versioned current-user and membership-scoped organization endpoints, organization and membership migrations, and feature tests. PostgreSQL migrations have not been run.

## Run locally

Requirements: Node.js 20+ and npm.

```sh
npm install
npm run dev
```

Backend setup is documented in [apps/api/README.md](apps/api/README.md). Configure a local PostgreSQL database before running its migrations.

The root `.env.example` sets `VITE_API_URL` for the local Laravel service. The API's `.env.example` documents the corresponding `FRONTEND_URL`, CORS origins and Sanctum stateful domains.

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
