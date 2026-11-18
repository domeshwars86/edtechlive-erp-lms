# Decision Log

## 2026-11-18 — Start with a React/Vite public frontend

**Context:** The repository was empty and the client explicitly prioritizes the public website before dashboards and backend. **Decision:** Scaffold a strict TypeScript React frontend with Vite and React Router. **Reason:** Fast, familiar component-based frontend foundation compatible with later dashboard development. **Trade-off:** Client rendering alone does not satisfy route-specific SEO rendering; evaluate prerendering before launch.

## 2026-11-18 — Keep site interactions honest

**Context:** Backend is intentionally a later milestone. **Decision:** Course listings are marked sample, prices provisional, contact submit preview-only, and auth screens explicitly disconnected. **Reason:** Avoid implying persistence, delivery, commercial terms or account operations that do not exist.

## 2026-11-18 — Plan a modular monolith and organization-scoped relational data

**Context:** The product requires multi-tenant ERP/LMS functions with a small-team maintainability target. **Decision:** Target Laravel REST API + PostgreSQL with organization membership and server policies; keep service integrations behind adapters. **Trade-off:** Detailed schema and authorization rules remain to be validated before migrations.
