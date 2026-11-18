# edTechLive

**Learn. Teach. Manage. Grow.**

An education ERP and learning platform for institutions, independent educators, students and families. This repository contains the public website, role-based dashboard previews and the first Laravel API/tenancy foundation. Account workflows and most business modules are not yet implemented.

## Current status

- Responsive public website with homepage, about, feature catalog, sample course discovery, institution/teacher/student solution pages, provisional pricing, contact form demonstration, authentication screens and draft legal pages.
- Responsive role dashboards at `/workspace/:role`: `super-admin`, `admin`, `management`, `teacher`, `student`, `parent`, `educator`.
- Course search and education-level filtering work locally.
- Contact form performs browser-side required-field and format validation, then displays an explicit preview-only success state. It does not transmit data.
- Login and registration screens are visual previews; no accounts, sessions or password operations exist yet.
- Example course listings and prices are not real offers. Paid plans are disabled.
- Public routes are statically pre-rendered with route-specific title and description metadata at build time.
- Dashboard pages use labelled illustrative data. Search and navigation work locally; data changes, authentication and account controls are not connected to an API.
- Laravel 13 API foundation lives in `apps/api`: Sanctum, versioned health/current-user/organization-list endpoints, organization and membership migrations, and isolated tests. PostgreSQL migrations have not been run.

## Run locally

Requirements: Node.js 20+ and npm.

```sh
npm install
npm run dev
```

Backend setup is documented in [apps/api/README.md](apps/api/README.md). Configure a local PostgreSQL database before running its migrations.

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
