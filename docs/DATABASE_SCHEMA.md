# Database Schema Direction

**Status:** Identity, organization, membership, academic session, class, section, and subject tables/models/migrations are implemented. Local development migrations are applied to SQLite; PostgreSQL deployment validation remains pending.

## Core entities

- `users`: identity and verified contact fields; never duplicate authentication secrets in domain records.
- `organizations`: tenant, type, status, locale/timezone and settings.
- `memberships`: user, organization, role/status, invitation/activation timestamps; unique active user/organization/role policy.
- `roles`, `permissions`, membership-role assignments: scoped authorization primitives.
- `academic_sessions`, `classes`, `sections`, `batches`, `subjects`: organization-owned academic structure.
- `students`, `teachers`, `guardians`, `guardian_student_links`: profiles and verified family relationships.
- `attendance_records`, `timetables`: class/session/date/user-scoped academic activity.
- `courses`, `enrollments`, `modules`, `lessons`, `learning_assets`: LMS structure and access.
- `assignments`, `submissions`, `exams`, `questions`, `exam_attempts`, `results`: assessment lifecycle and auditable scoring.
- `meetings`, `meeting_participants`: class/management meetings and provider/manual links.
- `notifications`, `announcements`, `audit_logs`: delivery state, communication and sensitive actions.
- `fee_categories`, `invoices`, `payments`, `payment_webhook_events`: finance records and idempotent provider events.
- `ai_usage_records`, provider configuration references: usage/cost tracking; secrets live outside database/application source.

The implemented schema uses `organizations` and `organization_memberships`, indexed by organization/user/status with a unique organization/user membership. `academic_sessions`, `academic_classes`, `academic_sections`, and `subjects` carry organization ownership and per-organization uniqueness constraints. Local SQLite migrations pass; PostgreSQL-specific migration and constraint behavior still needs validation. Role, organization type and membership status use PHP backed enums.

## Modeling rules

Every tenant-owned entity carries a non-null organization key and has tenant-aware foreign keys/unique constraints where PostgreSQL supports the design. Use UTC timestamps with organization timezone for display. Index organization plus common filter/sort keys. Define soft-delete vs. retention carefully; it is not a substitute for deletion policies. Store file metadata and private storage identifiers, not public permanent URLs. Use transactions for enrollment, assessment finalization and payment state changes. Webhook idempotency keys are unique.

## Open decisions

Exact role representation, enrollment lifecycle, academic calendar flexibility, attendance correction, exam scoring model, child-data retention, financial accounting, partitioning thresholds and data residency require discovery before migrations are finalized.
