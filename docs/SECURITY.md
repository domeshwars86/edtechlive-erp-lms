# Security Baseline

## Current state

The frontend still uses sample dashboard data. Laravel Sanctum session auth is connected to the web client; login, registration, verification and password reset endpoints are rate-limited. `/api/v1/me` and organization reads require authentication; organization reads additionally require verified email and active membership. Organization IDs selected in the client are never treated as authorization. Detailed role policies, membership revocation and real academic resources remain unimplemented. Do not enter real learner, child or institutional records.

## Required before production

- MFA for sensitive roles where practical, production email transport, and security review of identity/session configuration.
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
