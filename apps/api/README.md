# edTechLive API

Laravel 13 API backend. The repository includes Sanctum authentication, email verification and password reset, hashed organization invitations, tenant-scoped academic master and roster APIs, teacher assignments, daily attendance, weekly timetable, student/guardian views, attendance summaries, private course assets, and course, lesson, assignment, submission, grading, and quiz workflows.

## Requirements

- PHP 8.3+ with Laravel's required extensions and `pdo_pgsql` for the target database.
- Composer 2.x.
- PostgreSQL for production-shaped validation; SQLite is available for isolated local development and automated tests.

## Setup

```sh
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Create a local PostgreSQL database named `edtechlive`, then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in the untracked `.env`. `.env.example` uses PostgreSQL by default. Do not point this project at production records during development. To use a local SQLite file, set `DB_CONNECTION=sqlite` and `DB_DATABASE=database/local-dev.sqlite` in the untracked `.env`; SQLite support must be enabled in PHP.

For a local browser session, set `APP_URL=http://127.0.0.1:8001`, `FRONTEND_URL=http://127.0.0.1:4173`, and run `php artisan serve --host=127.0.0.1 --port=8001`. The root Vite app runs on port 4173 and proxies the API to port 8001 by default.

Set `FRONTEND_URL` to the SPA origin used in reset links, `CORS_ALLOWED_ORIGINS` to the allowed browser origins, and `SANCTUM_STATEFUL_DOMAINS` to the hostnames (including ports) of your first-party SPA. The example allows arbitrary local development ports only on `localhost` and `127.0.0.1`; use exact origins and hostnames for deployed environments. For cookie-based SPA auth, call `/sanctum/csrf-cookie`, then submit login with credentials and use the session cookie. For native clients, include a `device_name` during login and send the returned token as a Bearer token. Tokens expire after 30 days and can be revoked with logout.

## Migrations and tests

After confirming the local database target, migrate the development database:

```sh
php artisan migrate
php artisan test
vendor/bin/pint --test
```

The default CI API job uses in-memory SQLite. A separate PostgreSQL 16 CI job provisions an isolated service, applies fresh migrations, and runs the API suite. That job must execute successfully before PostgreSQL compatibility is considered verified. The ignored local development SQLite schema is not production data.

## Implemented API routes

- `GET /api/v1/health` — service/version health; it does not check database connectivity.
- `POST /api/v1/enquiries` — public contact intake, limited to six requests per minute; requires consent and persists enquiries. Set `ENQUIRY_RECIPIENT` and configure a non-log mail transport to notify the team.
- Under `/api/v1/organizations/{organization}/courses/{course}`: `GET /progress` returns the enrolled student's own completion or a course-scoped teacher/manager progress report; `PUT /lessons/{lesson}/progress` updates only the signed-in enrolled student's completion.
- Under `/api/v1/organizations/{organization}`: `GET/POST /fees` and `POST /fees/{fee}/payments` maintain manual fee records and staff-entered receipts. Students and explicitly linked parents can read only authorized fees; no payment is initiated or charged by these routes.
- Under `/api/v1/organizations/{organization}`: `GET/POST /meetings` and `PATCH /meetings/{meeting}` provide class and management meeting schedules with manual HTTPS provider links. Teachers need a matching assignment; student and parent listings are enrollment/link scoped. Google Meet room provisioning is not configured.
- `POST /api/v1/auth/register` — creates a user, organization and initial admin membership, then sends a verification email. Responds with 202.
- `POST /api/v1/auth/login` — verified accounts only. Uses a first-party session by default or issues a 30-day token when `device_name` is supplied.
- `POST /api/v1/auth/logout` — revokes the current token or ends the browser session.
- `POST /api/v1/auth/email/verification-notification` and `GET /api/v1/auth/verify-email/{id}/{hash}` — throttled resend and signed, expiring verification.
- `POST /api/v1/auth/forgot-password` and `POST /api/v1/auth/reset-password` — generic reset-link response and token-based reset. Reset links go to `FRONTEND_URL/reset-password`.
- `GET /api/v1/me` — Sanctum-authenticated current-user summary.
- `GET /api/v1/organizations` and `GET /api/v1/organizations/{organization}` — verified users can access active organizations where they have an active membership; list is paginated.
- Under `/api/v1/organizations/{organization}`: `GET/POST/PATCH academic-sessions`, `GET/POST/PATCH classes`, nested `GET/POST classes/{class}/sections`, `PATCH sections/{section}`, and `GET/POST/PATCH subjects`. Read access requires active organization membership; writes require admin, management, or independent educator role. All records are organization-scoped.
- Under `/api/v1/organizations/{organization}`: `GET/POST/PATCH students` and `GET/POST/PATCH teachers` provide paginated, searchable rosters. Access is limited to admin, management, and independent educator roles. Student class/section/session references are organization-validated.
- `GET/POST/DELETE /api/v1/organizations/{organization}/invitations` and `GET /api/v1/auth/invitations/{token}`, `POST /api/v1/auth/invitations/register`, and `POST /api/v1/organizations/{organization}/invitations/{token}/accept` provide role-limited, seven-day, one-time invitations. Teacher/student acceptance links the matching roster profile; parent invitations select linked students. Raw tokens are stored only as hashes.
- `GET/POST /api/v1/organizations/{organization}/teaching-assignments` with `DELETE` by ID manages tenant-scoped subject, section, session, and teacher assignments. Teachers can list only their own assignments.
- `GET/PUT /api/v1/organizations/{organization}/attendance` — paginated attendance register for active students in a section/session; dates are constrained to the session and batch marks upsert by student/section/date. Teachers can access only assigned sections; admin, management, and independent educator can access the organization register.
- `GET/POST/PATCH /api/v1/organizations/{organization}/timetable` — weekly entries by session, section, subject, optional assigned teacher/room and secure HTTPS meeting URL. Active section, teacher, and room overlaps are rejected. Teachers see only their own active entries. Google Meet API provisioning is not connected; links are entered manually.
- `GET /api/v1/organizations/{organization}/my/student` and `/my/student/attendance` — a student sees only their linked active profile and attendance.
- `GET /api/v1/organizations/{organization}/dashboard/activity` — seven-day organization-local lesson-completion and assignment-submission totals, scoped to published courses and active enrollments. Teacher courses, student profiles, and explicitly linked parent children are enforced; response contains aggregate counts only.
- `GET /api/v1/organizations/{organization}/dashboard/summary` — organization-timezone summary cards scoped to the user's active organization role.
- `GET /api/v1/organizations/{organization}/my/children` and `/my/children/{student}/attendance` — a parent sees only active students explicitly linked through an accepted invitation, and only their attendance.
- `GET /api/v1/organizations/{organization}/my/student/results` and `/my/children/{student}/results` — students read their own last 100 submitted quiz attempts and graded assignment outcomes; parents must have an explicit active child link. Draft assessment, in-progress, answer content and answer keys are excluded.
- `GET /api/v1/organizations/{organization}/reports/attendance?from={date}&to={date}` — admin, management, and independent educator receive date-filtered totals and class/section breakdowns; late marks count as attended.
- `GET /api/v1/organizations/{organization}/reports/enrollment` — admin, management, and independent educator receive aggregate active-student placement by session/section, unassigned learner totals, and active enrollments in published courses. Teachers and learners cannot access organization-wide totals.
- `GET /api/v1/organizations/{organization}/reports/workload` — admin, management, and independent educator receive active teacher section assignments, distinct active learner reach, and active weekly timetable slot/hours totals for academic sessions in progress on the organization's local date.
- `GET /api/v1/organizations/{organization}/reports/finance?from={date}&to={date}` — admin, management, and independent educator receive aggregate INR fees issued, staff-entered receipts, outstanding balance, and past-due balances by the organization's local date, plus receipts for a selected period (default last 30 days, maximum 366). It does not initiate payment.
- `GET /api/v1/organizations/{organization}/reports/assessments` — admin, management, and independent educator receive aggregate quiz and assignment outcomes, including grading backlog; teachers see only courses assigned to their teacher profile. Student identity is omitted.
- Under `/api/v1/organizations/{organization}`: `GET/POST /courses`, `PATCH /courses/{course}`, `GET/POST/PATCH /courses/{course}/modules`, `GET/POST /courses/{course}/lessons`, and `POST /courses/{course}/enrollments` provide course drafts, ordered modules, teacher-owned authoring, lesson publication, and manager-controlled student enrollment. Lessons may belong to one course module; students only see modules that contain published lessons in a published course with their active enrollment. Lesson resource links require HTTPS.
- Under the same organization prefix: `GET/POST /courses/{course}/assignments`, `PATCH /assignments/{assignment}`, `POST /assignments/{assignment}/submissions`, `GET /assignments/{assignment}/submissions`, and `PATCH /submissions/{submission}/grade` provide text assignments, one submission per student/assignment, and instructor grading/feedback. A graded submission cannot be changed; scores cannot exceed the assignment maximum.
- Under the same organization prefix: GET/POST /courses/{course}/quizzes, PATCH /quizzes/{quiz}, POST /quizzes/{quiz}/questions, POST /quizzes/{quiz}/attempts, PATCH /quizzes/{quiz}/attempts/{attempt}, and GET /quizzes/{quiz}/attempts provide timed multiple-choice tests. Correct choices are excluded from student responses; the server enforces enrollment, attempt limits, expiry, and scoring.
- Under the same organization prefix: GET/POST /courses/{course}/assets, PATCH /assets/{asset}, and GET /assets/{asset}/download provide allow-listed private course files (maximum 20 MB). Student listing and download require active enrollment and publication; storage keys are never returned. Local development uses the private filesystem disk; production needs durable private object storage.

Direct guardian-link management, wider academic reports, broader examinations, malware scanning and production private file storage remain open. Local `.env` uses the log mailer, so messages are recorded locally rather than delivered. Production email delivery, PostgreSQL migration, and deployment are not configured here.
