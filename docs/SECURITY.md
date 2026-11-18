# Security Baseline

## Current state

Laravel Sanctum session auth is connected to the web client; login, registration, verification and password reset endpoints are rate-limited. `/api/v1/me` and organization reads require authentication; organization reads additionally require verified email and active membership. Organization IDs selected in the client are never treated as authorization. Core academic, attendance, family, reporting and initial LMS records have tenant-aware routes; broader policies, membership revocation, audit logging, MFA and production data controls remain open. Several platform-level panels and non-academic workflows still use sample content. Do not enter production learner, child or institutional records before the deployment and privacy gates are complete.

## Required before production

- MFA for sensitive roles where practical, production email transport, and security review of identity/session configuration.
- Server-side tenant/resource authorization, membership revocation, least privilege and automated cross-tenant denial tests.
- CSRF/XSS protections, strict input validation, safe output encoding, secure headers and dependency review.
- Production private object storage and durable retention/deletion controls for uploads; the current API allow-lists formats and sizes, and rechecks enrollment/publication for each download. Decide and add malware scanning before accepting untrusted files in production.
- Secret manager/environment configuration; no credentials in repository or logs.
- HTTPS, encryption-at-rest strategy, backup encryption, tested recovery and access logging.
- Minimized audit records, redaction of student data from telemetry and safe notification previews.
- Contact enquiries require explicit consent, are rate-limited and validated, and use a honeypot for basic bot filtering. The global inbox requires a verified account whose exact email is configured in `ENQUIRY_ADMIN_EMAILS`; organization membership does not grant access. Define a retention/deletion schedule before launch.
- The live platform overview and operations workspace require a verified account whose email is explicitly listed in the separate `PLATFORM_ADMIN_EMAILS` allowlist. Keep both operator allowlists empty or narrowly scoped by default; an organization role never grants global platform access.
- Child/student data privacy, consent, processing agreements, retention, deletion and incident procedures reviewed by qualified counsel.
- AI provider review, data minimization, contractual processing protections, opt-in/usage limits and human review; no automated grading authority.
- Payment provider signature verification, idempotency and sandbox testing before any production merchant activation.
- Financial data access must remain tenant-scoped; only authorized staff may issue fees or record receipts, while students and explicitly linked guardians receive read-only views. The current ledger does not initiate payments; reconcile manual receipts and apply financial retention controls before production.
- Meeting links are exposed only to authorized organization roles and scoped learners/guardians; require HTTPS and avoid placing credentials or access codes in URLs or logs.

Legal applicability depends on users, jurisdiction and actual product operation; confirm before launch.
