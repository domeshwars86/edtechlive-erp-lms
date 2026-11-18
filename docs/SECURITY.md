# Security Baseline

## Current state

The frontend still uses sample data and demonstration forms. Laravel Sanctum is installed. `/api/v1/me` and `/api/v1/organizations` require `auth:sanctum`; the organization list queries only active memberships belonging to the authenticated user. Registration/login and tenant-aware resource policies are not implemented yet. No real learner, child or institutional records should be entered.

## Required before production

- Secure identity/session lifecycle, password hashing, rate limits and MFA for sensitive roles where practical.
- Server-side tenant/resource authorization, membership revocation, least privilege and automated cross-tenant denial tests.
- CSRF/XSS protections, strict input validation, safe output encoding, secure headers and dependency review.
- Private file uploads with allow-listed formats, size limits, malware scanning decision, authorization on every download and retention/deletion controls.
- Secret manager/environment configuration; no credentials in repository or logs.
- HTTPS, encryption-at-rest strategy, backup encryption, tested recovery and access logging.
- Minimized audit records, redaction of student data from telemetry and safe notification previews.
- Child/student data privacy, consent, processing agreements, retention, deletion and incident procedures reviewed by qualified counsel.
- AI provider review, data minimization, contractual processing protections, opt-in/usage limits and human review; no automated grading authority.
- Payment provider signature verification, idempotency and sandbox testing before any production merchant activation.

Legal applicability depends on users, jurisdiction and actual product operation; confirm before launch.
