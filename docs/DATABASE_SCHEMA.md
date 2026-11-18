# Database Schema Direction

**Status:** Identity, organization, membership, invitation, academic session, class, section, subject, student, teacher, student/guardian links, courses/lessons/enrollment/assets, assignment/submissions, quiz questions/attempts, teaching assignment, attendance, and timetable tables/models/migrations are implemented. Local development migrations are applied to SQLite; PostgreSQL deployment validation remains pending.

## Core entities

- `users`: identity and verified contact fields; never duplicate authentication secrets in domain records.
- `organizations`: tenant, type, status, locale/timezone and settings.
- `memberships`: user, organization, role/status, invitation/activation timestamps; unique active user/organization/role policy.
- `organization_invitations`: organization, normalized email, limited role, hashed one-time token, inviter, expiry, and acceptance audit fields. Raw invitation tokens exist only in the mail link.
- `contact_enquiries`: public prospective-user submissions with contact details, consent timestamp, workflow status, and optional notification timestamp. This table is not tenant-owned; access is not exposed through public read APIs.
- `roles`, `permissions`, membership-role assignments: scoped authorization primitives.
- `academic_sessions`, `classes`, `sections`, `batches`, `subjects`: organization-owned academic structure.
- `students`, `teachers`: organization-owned roster profiles with optional class placement and organization-scoped admission/employee identifiers. Teacher profiles link to one user account; student profiles can link to one user account. `student_guardians` links a guardian account to explicitly authorized student profiles, with relationship, primary-contact and inviter metadata.
- `organization_invitation_students`: tenant-scoped selected student profiles attached to parent invitations; links are created only after successful invitation acceptance.
- `teaching_assignments`: tenant-owned teacher/subject/section/session links; timetable entries require the matching assignment when a teacher is set.
- `attendance_records`, `timetable_entries`: class/session/date/user-scoped academic activity. Attendance has one mark per student/section/date and records the marking user. Timetable entries contain weekday/time, room, optional assigned teacher, and optional secure meeting URL.
- `courses`, `course_enrollments`, `course_lessons`: implemented course drafts/publication, active student enrollment, and ordered text/HTTPS lesson content. `course_assignments` and `assignment_submissions`: published prompts, due dates, student text responses, numeric grades and instructor feedback. `course_quizzes`, `quiz_questions`, and `quiz_attempts`: timed multiple-choice quizzes, private answer keys, bounded attempts, and server-scored results. `course_assets` stores tenant/course metadata and opaque private storage keys; downloads go through an authorized API route. Modules, private learning assets, broader exams, and progress records remain planned.
- `assignments`, `submissions`, `exams`, `questions`, `exam_attempts`, `results`: assessment lifecycle and auditable scoring.
- `meetings`, `meeting_participants`: class/management meetings and provider/manual links.
- `notifications`, `announcements`, `audit_logs`: delivery state, communication and sensitive actions.
- `fee_categories`, `invoices`, `payments`, `payment_webhook_events`: finance records and idempotent provider events.
- `ai_usage_records`, provider configuration references: usage/cost tracking; secrets live outside database/application source.

The implemented schema uses `organizations` and `organization_memberships`, indexed by organization/user/status with a unique organization/user membership. `organization_invitations`, `organization_invitation_students`, `student_guardians`, `courses`, `course_enrollments`, `course_lessons`, `course_assignments`, `assignment_submissions`, `course_quizzes`, `quiz_questions`, `quiz_attempts`, `academic_sessions`, `academic_classes`, `academic_sections`, `subjects`, `students`, `teachers`, `teaching_assignments`, `attendance_records`, and `timetable_entries` carry organization ownership and tenant-aware validation/index constraints. Attendance reports aggregate existing records and require no separate materialized table. Local SQLite migrations pass; PostgreSQL-specific migration and constraint behavior still needs validation. Role, organization type and membership status use PHP backed enums.

## Modeling rules

Every tenant-owned entity carries a non-null organization key and has tenant-aware foreign keys/unique constraints where PostgreSQL supports the design. Use UTC timestamps with organization timezone for display. Index organization plus common filter/sort keys. Define soft-delete vs. retention carefully; it is not a substitute for deletion policies. Store file metadata and private storage identifiers, not public permanent URLs. Use transactions for enrollment, assessment finalization and payment state changes. Webhook idempotency keys are unique.

## Open decisions

Exact role representation, enrollment lifecycle, academic calendar flexibility, attendance correction, exam scoring model, child-data retention, financial accounting, partitioning thresholds and data residency require discovery before migrations are finalized.
