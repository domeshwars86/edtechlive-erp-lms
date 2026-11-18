# API Specification (Proposed)

**Status:** Initial `/api/v1` Laravel routes are implemented. The other routes below remain proposals.

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
| `GET /api/v1/me` | Sanctum authenticated | Returns current user's ID, name, email and verification timestamp; omits credentials. |
| `GET /api/v1/organizations` | Sanctum authenticated | Returns paginated active organization memberships for the current user only, including role and basic organization details. `per_page` is bounded to 1–100. |

Document schemas, error codes, scopes and OpenAPI contracts alongside implementation; do not advertise proposed endpoints as available.
