# API Specification (Proposed)

**Status:** Health, authentication, password recovery, email verification, organization invitations, current-user, organization reads, academic masters, people rosters, teaching assignments, attendance, timetable, family access, attendance reports, courses, lessons, enrollments, assignments, submissions, and grading routes are implemented. Other routes remain proposals.

Base path: `/api/v1`. JSON requests/responses. Authenticated endpoints return consistent validation and authorization errors; lists are paginated. Organization context is explicit in authorized routes and verified against the authenticated user's active membership. Never trust a client-provided organization ID without policy enforcement.

| Area | Proposed routes |
|---|---|
| Health | `GET /health` |
| Identity | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `POST /auth/forgot-password`, `POST /auth/reset-password`, `GET /me` |
| Organizations | `GET/POST /organizations`, `GET/PATCH /organizations/{organization}`, `POST /organizations/{organization}/switch`, `POST /organizations/{organization}/invitations` |
| Academic masters | `GET/POST /organizations/{organization}/academic-sessions`, `/classes`, `/sections`, `/subjects`, `/batches` |
| People | `GET/POST /organizations/{organization}/students`, `/teachers`, `/memberships`; relationship-specific guardian routes |
| Learning | `GET/POST /organizations/{organization}/courses`, `/courses/{course}/modules`, `/lessons`, `/enrollments`, `/assets` |
| Attendance/schedule | `GET/POST /organizations/{organization}/attendance`, `/schedules` |
| Assessment | `GET/POST /organizations/{organization}/assignments`, `/submissions`, `/exams`, `/attempts`, `/results` |
| Meetings | `GET/POST /organizations/{organization}/meetings`; provider creation is optional, manual URL entry supported |
| Reports/notifications | `GET /organizations/{organization}/reports/*`, `GET/PATCH /notifications`, `POST /notifications/{id}/read` |
| Enquiries | `POST /enquiries` with throttling, validation and anti-abuse controls |
| Finance | `GET/POST /organizations/{organization}/invoices`, `POST /payments/intents`, signed provider webhook endpoint |
| AI | `POST /ai/explanations`, `/ai/question-drafts` with authorization, quotas and moderation |

## Implemented routes

| Method and path | Access | Behavior |
|---|---|---|
| `GET /api/v1/health` | Public | Returns service health and API version; does not assert database connectivity. |
| `POST /api/v1/auth/register` | Public, throttled | Validates name, email, 12+ character letter-and-number password, organization name/type; creates user, organization, and initial active membership in one transaction; sends verification. Duplicate-email response is intentionally generic. |
| `POST /api/v1/auth/login` | Public, throttled | Requires verified account and valid credentials. Without `device_name`, creates a first-party browser session. With `device_name`, issues a 30-day Sanctum bearer token. |
| `POST /api/v1/auth/logout` | Sanctum authenticated | Deletes the current bearer token or invalidates the browser session. |
| `POST /api/v1/auth/email/verification-notification` | Public, throttled | Sends a verification message when an unverified account exists; response does not reveal account existence. |
| `GET /api/v1/auth/verify-email/{id}/{hash}` | Signed, throttled | Validates the expiring signed URL and marks the matching user email as verified. |
| `POST /api/v1/auth/forgot-password` | Public, throttled | Sends password reset instructions when an account exists; response is generic. Link is built for `FRONTEND_URL/reset-password`. |
| `POST /api/v1/auth/reset-password` | Public, throttled | Accepts email, reset token, and confirmed password; consumes the reset token and revokes existing API tokens. |
| `GET /api/v1/me` | Sanctum authenticated | Returns current user's ID, name, email and verification timestamp; omits credentials. |
| `GET /api/v1/organizations` | Sanctum authenticated and verified | Returns paginated active organization memberships for the current user only, including role and basic organization details. `per_page` is bounded to 1–100. |
| `GET /api/v1/organizations/{organization}` | Sanctum authenticated and verified | Returns organization details only to an active member; other users receive 404. |
| `GET /api/v1/organizations/{organization}/invitations` | Admin, management, or independent educator in an active verified membership | Lists tenant invitations with pending, accepted, or expired state. |
| `POST /api/v1/organizations/{organization}/invitations` | Same roles; management invitations are admin-only | Sends a seven-day invitation to a permitted role. Student invites require an active matching student roster email; parent invites require 1–20 active tenant student IDs. Raw tokens are never returned by the API; invitations can be revoked before acceptance. |
| `DELETE /api/v1/organizations/{organization}/invitations/{record}` | Same roles | Revokes a pending invitation. Accepted invitations cannot be revoked through this route. |
| `GET /api/v1/auth/invitations/{token}` | Public, throttled | Returns invitation email, role, organization name, and expiry for a valid one-time token. |
| `POST /api/v1/auth/invitations/register` | Public, throttled | New invitees create an account and accept; existing invitees confirm with their account password. The invitation proves email access and activates a membership. Teacher and student acceptance link the matching roster profile; parent acceptance links only the student profiles selected on the invitation. |
| `POST /api/v1/organizations/{organization}/invitations/{token}/accept` | Authenticated, verified invitee whose email matches the invitation | Accepts an invitation for an existing signed-in account. |
| `GET/POST /api/v1/organizations/{organization}/academic-sessions` | Verified active organization member; writes by admin, management, or independent educator | Lists or creates tenant-scoped sessions. Dates must be ordered; session names are unique per organization; current-session switching is transactional. |
| `PATCH /api/v1/organizations/{organization}/academic-sessions/{record}` | Same as above | Updates a tenant-scoped session; inactive sessions cannot be current. |
| `GET/POST /api/v1/organizations/{organization}/classes` | Verified active organization member; writes by admin, management, or independent educator | Lists or creates organization classes with optional unique code and sort order. |
| `PATCH /api/v1/organizations/{organization}/classes/{record}` | Same as above | Updates name, code, sort order, or active state. |
| `GET/POST /api/v1/organizations/{organization}/classes/{class}/sections` | Verified active organization member; writes by admin, management, or independent educator | Lists or creates class sections; an academic session reference must belong to the same organization. |
| `PATCH /api/v1/organizations/{organization}/sections/{record}` | Same as above | Updates section name, session, capacity, or active state. |
| `GET/POST /api/v1/organizations/{organization}/subjects` | Verified active organization member; writes by admin, management, or independent educator | Lists or creates subjects with optional unique code and description. |
| `PATCH /api/v1/organizations/{organization}/subjects/{record}` | Same as above | Updates subject details or active state. |
| `GET/POST /api/v1/organizations/{organization}/students` | Admin, management, or independent educator in an active verified membership | Lists a paginated roster with search/status filters or creates a student profile. Enrollment class, section, and session references must be organization-owned. |
| `PATCH /api/v1/organizations/{organization}/students/{record}` | Same roles | Updates profile, placement, enrollment date, or lifecycle status. Student records are not login accounts. |
| `GET/POST /api/v1/organizations/{organization}/teachers` | Same roles | Lists a paginated staff roster with search/status filters or creates a teacher profile. |
| `PATCH /api/v1/organizations/{organization}/teachers/{record}` | Same roles | Updates teacher contact/specialization details or status. Staff login invitations are not included yet. |
| `GET /api/v1/organizations/{organization}/teaching-assignments` | Admin, management, or independent educator; teachers see their own linked profile assignments | Lists teacher/subject/section assignments, optionally filtered by session and teacher. |
| `POST /api/v1/organizations/{organization}/teaching-assignments` | Same roles | Assigns an active teacher to a subject and section in a matching active academic session; repeated requests are idempotent. |
| `DELETE /api/v1/organizations/{organization}/teaching-assignments/{record}` | Same roles | Removes a tenant-owned teaching assignment. A teacher cannot be scheduled for the matching section/subject/session without this assignment. |
| `GET /api/v1/organizations/{organization}/attendance` | Admin, management, independent educator, or linked teacher assigned to the section/session | Returns a paginated section roster with saved marks for a date inside the section's academic session. |
| `PUT /api/v1/organizations/{organization}/attendance` | Same roles | Batch upserts present/absent/late/excused marks and optional notes. Students must be active and enrolled in that section/session; teachers can mark only assigned sections. |
| `GET /api/v1/organizations/{organization}/my/student` | Verified active student member | Returns the active student roster profile linked to the signed-in account. |
| `GET /api/v1/organizations/{organization}/my/student/attendance` | Verified active student member | Returns paginated attendance only for the signed-in student's linked roster profile; optional `from` and `to` date filters are supported. |
| `GET /api/v1/organizations/{organization}/my/children` | Verified active parent member | Returns only active student profiles explicitly linked to the signed-in parent. |
| `GET /api/v1/organizations/{organization}/my/children/{student}/attendance` | Verified parent linked to the requested student | Returns paginated attendance only for the explicitly linked active child; other students return 404. |
| `GET /api/v1/organizations/{organization}/reports/attendance?from={date}&to={date}` | Verified admin, management, or independent educator member | Returns date-filtered attendance totals and class/section breakdowns. Late marks count toward attendance rate. Optional `session_id` is tenant validated; end date must not precede start date. |
| `GET /api/v1/organizations/{organization}/courses` | Verified manager, linked teacher, or linked student | Managers see organization courses, teachers see courses linked to their teacher profile, and students see only published courses with an active enrollment. |
| `POST /api/v1/organizations/{organization}/courses` | Verified manager or linked teacher | Creates a draft course. Optional subject/teacher references must belong to the organization; teacher-created courses are linked to their own roster profile. |
| `PATCH /api/v1/organizations/{organization}/courses/{record}` | Verified manager or course's linked teacher | Updates course details/status. Publishing requires a published lesson. |
| `GET/POST /api/v1/organizations/{organization}/courses/{course}/lessons` | Course manager/editor; enrolled student may read published lessons | Adds lesson content or an HTTPS resource link; publishing requires at least one content body or HTTPS resource URL. Students cannot read drafts or courses without enrollment. |
| `POST /api/v1/organizations/{organization}/courses/{course}/enrollments` | Verified manager | Enrolls 1–200 active student roster profiles into a published course; repeated enrollment reactivates the same course/student record. |
| `GET/POST /api/v1/organizations/{organization}/courses/{course}/assignments` | Course manager/editor; enrolled student may list published assignments | Creates draft/published assignments or lists available work. Students see only published assignments for an active course enrollment; each item includes only their own submission/grade. |
| `PATCH /api/v1/organizations/{organization}/assignments/{record}` | Course manager or linked teacher | Updates assignment prompt, due date, maximum score, or publication status. |
| `POST /api/v1/organizations/{organization}/assignments/{assignment}/submissions` | Linked student actively enrolled in the published course | Creates or updates the student's text response until it has been graded. Each assignment/student pair has one submission record. Late work is accepted and retains its submission timestamp. |
| `GET /api/v1/organizations/{organization}/assignments/{assignment}/submissions` | Course manager or linked teacher | Lists submissions for one tenant-owned assignment, including student roster name and admission number. |
| `PATCH /api/v1/organizations/{organization}/submissions/{submission}/grade` | Course manager or linked teacher | Stores a numeric score no higher than the assignment maximum and optional feedback; records grader and grading time. |
| `GET /api/v1/organizations/{organization}/timetable` | Admin, management, independent educator, or linked teacher | Lists paginated weekly entries with optional session, section, and weekday filters; teachers only see their own entries. |
| `POST/PATCH /api/v1/organizations/{organization}/timetable` | Same roles | Creates or updates a section/subject slot, optional assigned teacher/room/HTTPS meeting link, and rejects active section, teacher, or room conflicts. |

Browser clients use Sanctum's stateful session flow with `/sanctum/csrf-cookie`; configure `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, and `FRONTEND_URL` for each environment. Native clients request a token by including `device_name` on login. Email delivery must be configured for verification and reset links to reach users.

Document schemas, error codes, scopes and OpenAPI contracts alongside implementation; do not advertise proposed endpoints as available.
