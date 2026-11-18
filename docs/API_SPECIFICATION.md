# API Specification (Proposed)

**Status:** Health, authentication, password recovery, email verification, current-user, and organization-read routes are implemented. The other routes below remain proposals.

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

Browser clients use Sanctum's stateful session flow with `/sanctum/csrf-cookie`; configure `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, and `FRONTEND_URL` for each environment. Native clients request a token by including `device_name` on login. Email delivery must be configured for verification and reset links to reach users.

Document schemas, error codes, scopes and OpenAPI contracts alongside implementation; do not advertise proposed endpoints as available.
