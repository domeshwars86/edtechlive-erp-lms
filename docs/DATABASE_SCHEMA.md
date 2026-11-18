# Database Schema Direction

**Status:** Identity, organization, membership, invitation, academic session, class, section, subject, student, teacher, student/guardian links, courses/modules/lessons/enrollment/progress/assets, assignment/submissions, quiz questions/attempts, fees/receipts, statement reconciliation, teaching assignment, attendance, timetable, meetings, and in-app notifications tables/models/migrations are implemented. Local development migrations are applied to SQLite; PostgreSQL deployment validation remains pending.

## Core entities

- `users`: identity and verified contact fields; never duplicate authentication secrets in domain records.
- `organizations`: tenant, type, status, locale/timezone and settings.
- `memberships`: user, organization, role/status, invitation/activation timestamps; unique active user/organization/role policy.
- `organization_invitations`: organization, normalized email, limited role, hashed one-time token, inviter, expiry, and acceptance audit fields. Raw invitation tokens exist only in the mail link.
- `contact_enquiries`: public prospective-user submissions with contact details, consent timestamp, workflow status, and optional notification timestamp. This table is not tenant-owned; access is not exposed through public read APIs.
- `fee_records`, `fee_payments`: tenant-owned INR receivables for active students and staff-entered partial/final receipts. Each receipt stores the staff user, method, optional reference, and received timestamp; this is a manual ledger, not a gateway transaction.
- `bank_statements`, `bank_statement_lines`: tenant-owned CSV-imported statement periods, account labels, opening/closing balances, a unique-per-tenant content fingerprint and transaction lines. Statement lines may be linked one-to-one to a same-tenant fee receipt or expense; matched ledger IDs are unique across statements. No account number, bank credential, or raw uploaded statement is retained.
- `meetings`: tenant-owned one-time class or management meeting with optional section/session or published course scope, HTTPS meeting URL, start/end time, creator, and scheduled/cancelled state. No provider credentials or participant secrets are stored.
- `notifications`: Laravel database notifications addressed to individual users; payloads carry organization and event identifiers for fee issue/receipt and meeting scheduling. Reads and read receipts are restricted to the recipient and active organization membership.
- `roles`, `permissions`, membership-role assignments: scoped authorization primitives.
- `academic_sessions`, `classes`, `sections`, `batches`, `subjects`: organization-owned academic structure.
- `students`, `teachers`: organization-owned roster profiles with optional class placement and organization-scoped admission/employee identifiers. Teacher profiles link to one user account; student profiles can link to one user account. `student_guardians` links a guardian account to explicitly authorized student profiles, with relationship, primary-contact and inviter metadata.
- `organization_invitation_students`: tenant-scoped selected student profiles attached to parent invitations; links are created only after successful invitation acceptance.
- `teaching_assignments`: tenant-owned teacher/subject/section/session links; timetable entries require the matching assignment when a teacher is set.
- `attendance_records`, `timetable_entries`: class/session/date/user-scoped academic activity. Attendance has one mark per student/section/date and records the marking user. Timetable entries contain weekday/time, room, optional assigned teacher, and optional secure meeting URL.
- `courses`, `course_modules`, `course_enrollments`, `course_lessons`, `course_lesson_progress`: course drafts/publication, ordered tenant-scoped modules, active student enrollment, ordered lessons optionally grouped by module, and per-student completion timestamps for published lessons. `course_assignments` and `assignment_submissions`: published prompts, due dates, student text responses, numeric grades and instructor feedback. `course_quizzes`, `quiz_questions`, and `quiz_attempts`: timed multiple-choice quizzes, private answer keys, bounded attempts, and server-scored results. `course_assets` stores tenant/course metadata and opaque private storage keys; downloads go through an authorized API route. Broader exams and staff-wide progress reporting remain planned.
- `assignments`, `submissions`, `exams`, `questions`, `exam_attempts`, `results`: assessment lifecycle and auditable scoring.
- `meetings`, `meeting_participants`: class/management meetings and provider/manual links.
- `notifications`, `announcements`, `audit_logs`: delivery state, communication and sensitive actions.
- `fee_categories`, `invoices`, `payments`, `payment_webhook_events`: finance records and idempotent provider events.
- `ai_usage_records`, provider configuration references: usage/cost tracking; secrets live outside database/application source.

The implemented schema uses `organizations` and `organization_memberships`, indexed by organization/user/status with a unique organization/user membership. `organization_invitations`, `organization_invitation_students`, `student_guardians`, `courses`, `course_modules`, `course_enrollments`, `course_lessons`, `course_assignments`, `assignment_submissions`, `course_quizzes`, `quiz_questions`, `quiz_attempts`, `academic_sessions`, `academic_classes`, `academic_sections`, `subjects`, `students`, `teachers`, `teaching_assignments`, `attendance_records`, and `timetable_entries` carry organization ownership and tenant-aware validation/index constraints. Attendance, enrollment and course-progress reports aggregate existing records and require no separate materialized table. Local SQLite migrations pass; PostgreSQL-specific migration and constraint behavior still needs validation. Role, organization type and membership status use PHP backed enums.

## Modeling rules

Every tenant-owned entity carries a non-null organization key and has tenant-aware foreign keys/unique constraints where PostgreSQL supports the design. Use UTC timestamps with organization timezone for display. Index organization plus common filter/sort keys. Define soft-delete vs. retention carefully; it is not a substitute for deletion policies. Store file metadata and private storage identifiers, not public permanent URLs. Use transactions for enrollment, assessment finalization and payment state changes. Webhook idempotency keys are unique.

## Open decisions

Exact role representation, enrollment lifecycle, academic calendar flexibility, attendance correction, exam scoring model, child-data retention, financial accounting, partitioning thresholds and data residency require discovery before migrations are finalized.
