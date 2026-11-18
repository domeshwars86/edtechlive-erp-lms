# Architecture

## Current implementation

The repository is a Vite-hosted React 19 / TypeScript frontend with React Router. Public routes, shared header/footer, sample course filtering, browser-native contact validation and responsive design systems live under `src/`. Public routes are statically pre-rendered by `src/entry-server.tsx` and `scripts/prerender.mjs`. The React client connects to Laravel for session-based login, registration, password recovery, current-user lookup and membership-scoped organization selection. Dashboard business records remain previews. Laravel 13/Sanctum provides the versioned API and first organization/membership schema; PostgreSQL migrations have not been applied.

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

Keep public, authentication and dashboard layouts separate, then organize feature modules and generated/shared API contracts. The initial auth API client uses credentialed Fetch and Laravel's CSRF cookie flow; account state is held in memory and rechecked from `/api/v1/me` on page load. The selected organization is revalidated against the API membership list and is not trusted as an authorization boundary. Add TanStack Query, React Hook Form and Zod where business workflows justify them. Route-specific SEO rendering/prerendering is an open decision before launch. Avoid treating client state as persisted business data.

## Decisions and open design

- Begin as a modular monolith, not microservices.
- Do not select a production file-storage vendor until cost, region, access-control and backup requirements are known.
- Prefer Laravel Sanctum for first-party browser/mobile authentication, subject to cookie/token threat-model review.
- Add Redis only when queue/cache needs justify operations and cost.
- Keep AI and payment providers behind adapters and disabled until their configuration and data governance are approved.
- See `DECISIONS.md` for the current decision log.
