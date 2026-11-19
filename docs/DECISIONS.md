# Decision Log

## 2026-11-18 — Start with a React/Vite public frontend

**Context:** The repository was empty and the client explicitly prioritizes the public website before dashboards and backend. **Decision:** Scaffold a strict TypeScript React frontend with Vite and React Router. **Reason:** Fast, familiar component-based frontend foundation compatible with later dashboard development. **Trade-off:** Client rendering alone does not satisfy route-specific SEO rendering; evaluate prerendering before launch.

## 2026-11-18 — Keep site interactions honest

**Context:** Backend was initially a later milestone. **Decision:** Course listings remain marked sample and prices provisional; contact submissions and account operations were later connected to the API. **Reason:** Avoid implying operational capability where data or delivery is still only demonstrative. See the current feature matrix for the boundary between live workflows and previews.

## 2026-11-18 — Plan a modular monolith and organization-scoped relational data

**Context:** The product requires multi-tenant ERP/LMS functions with a small-team maintainability target. **Decision:** Use a Laravel REST API and relational tenant-owned records with organization membership and server-side checks; keep service integrations behind adapters. **Trade-off:** Implemented workflows have SQLite and PostgreSQL 17 regression coverage, while deployment and parts of the detailed permission model remain to be validated.

## 2026-11-19 — Connect account flows and keep local verification mail inspectable

**Context:** The React client now uses Laravel Sanctum for registration, email verification, login, password reset and current-user/session state. **Decision:** Use Laravel's log mailer for local development and disclose that signed links are written to the local API log; production requires a configured mail transport. **Trade-off:** A local account is not email-verified until the developer opens the logged verification link.

## 2026-11-19 — Record the boundary of platform administration

**Context:** Platform operators need a way to manage organization availability. **Decision:** Only an explicitly allowlisted, verified operator may suspend/reactivate an organization, with a required reason and transactional audit event. **Trade-off:** Broader account, role, billing and support administration is still planned; this control does not imply access to learner records.

## 2026-11-19 — Send enquiry replies without retaining reply content

**Context:** Prospective contacts need a usable follow-up path, and enquiry data requires minimal retention. **Decision:** Verified allowlisted inbox operators can send plain-text email replies through the configured transport; store only the latest responder and timestamp, not the reply body. A transport failure does not mark the enquiry replied. **Trade-off:** The system does not provide a content transcript or delivery/read receipts.

## 2026-11-19 — Keep PostgreSQL sessions in UTC

**Context:** Scheduled quizzes compare persisted timestamps with Laravel's UTC timestamps. **Decision:** Configure Laravel's PostgreSQL connector to set every database session to UTC. **Trade-off:** Organization-local schedules must be converted at the client/API boundary and localized for display; PostgreSQL host timezone settings no longer affect timestamp interpretation.

## 2026-11-19 — Scope reusable question banks to a course

**Context:** Course quizzes need reusable questions while preserving tenant and course boundaries. **Decision:** Store banks and bank questions under a course, authorize every operation through the existing course editor check, and copy a selected question into a draft quiz as a snapshot. **Trade-off:** Later bank edits or deletion do not silently change existing quizzes; detailed bank change history is not implemented.

## 2026-11-19 — Keep subjective quiz grading separate from student answers

**Context:** Quizzes need teacher-reviewed written responses without leaking answer keys or marking a partial score as final. **Decision:** Keep submitted text in the attempt answer map, store per-question grades/feedback and reviewer metadata separately, and expose a pending/partial/graded state. Only course editors can grade; aggregate score averages omit pending and partial attempts. **Trade-off:** The browser quiz workspace is the grading interface; bulk review and grade-change history remain future work.
