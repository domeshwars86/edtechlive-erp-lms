# Architecture

## Current implementation

The repository contains a Vite-hosted React 19 / TypeScript frontend with React Router, a Laravel 13 / Sanctum API, and an Expo React Native application. Public routes are statically pre-rendered by `src/entry-server.tsx` and `scripts/prerender.mjs`. The web client connects to Laravel for session-based authentication and tenant-scoped academic, LMS, attendance, reporting, finance, meetings and notification workflows. Some platform and provider workflows remain previews or planned work; see `FEATURE_MATRIX.md`. Implemented migrations have passed the API suite on SQLite and a disposable PostgreSQL 17 instance. This is local verification, not production database or deployment validation.

## Target architecture

Modular monolith, versioned REST API and separate clients:

```text
Public and role-based React web ─┐
React Native Android/iOS ────────┼── HTTPS /api/v1 ─ Laravel domains ─ PostgreSQL
                                │                  ├─ Queue/cache (Redis where justified)
                                │                  ├─ Private object storage
                                │                  └─ Provider adapters (email, Meet, payment, AI)
```

Laravel domain boundaries: Identity, Tenancy, Administration, Academic ERP, Teachers, Students, LMS, Assignments, Exams, Live Classes, Notifications, Payments, Analytics, AI and Integrations. Use form requests, policies, API resources, actions/services, events, transactions, queued jobs and audit records as appropriate. Avoid microservices until an evidenced need exists.

## Tenancy

Use shared relational tables with organization foreign keys for organization-owned data, membership-based user access and centralized tenant scoping. Add compound tenant-aware indexes and unique constraints. Ensure object-storage paths and signed download authorization include tenant/resource authorization. Cross-tenant access tests are release-critical. Consider independent educator workspace as an organization type.

## Frontend

Keep public, authentication and dashboard layouts separate, then organize feature modules and generated/shared API contracts. The auth API client uses credentialed Fetch and Laravel's CSRF cookie flow; account state is held in memory and rechecked from `/api/v1/me` on page load. The selected organization is revalidated against the API membership list and is not trusted as an authorization boundary. Add TanStack Query, React Hook Form and Zod where business workflows justify them. Route prerendering exists; metadata coverage and deployed SEO behavior still need review. Avoid treating client state as persisted business data.

## Decisions and open design

- Begin as a modular monolith, not microservices.
- Do not select a production file-storage vendor until cost, region, access-control and backup requirements are known.
- Prefer Laravel Sanctum for first-party browser/mobile authentication, subject to cookie/token threat-model review.
- Add Redis only when queue/cache needs justify operations and cost.
- Keep AI and payment providers behind adapters and disabled until their configuration and data governance are approved.
- See `DECISIONS.md` for the current decision log.
