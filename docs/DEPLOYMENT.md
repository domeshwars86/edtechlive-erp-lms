# Deployment

## Current status

No deployment configuration, domain, credentials or staging service is present. Nothing has been deployed. `.github/workflows/quality.yml` runs web typecheck/lint/build, Laravel tests/Pint, and mobile typecheck/Android JS export on pushes and pull requests. It does not deploy or need application secrets.

## Website preview

The frontend builds to Vite's `dist/` directory and can be served as static assets. SPA route fallback must be configured by the eventual host. Before public launch, use the real canonical domain in metadata and sitemap and decide whether public pages need prerendering for search engines.

## Target Render architecture

- React web client/static assets.
- Laravel API service/container.
- Managed PostgreSQL with backups and restore procedure.
- Background worker and scheduler.
- Redis-compatible service only if justified.
- Private durable external object storage for uploads and recordings.
- Monitoring, structured logs, health checks and alerting.

## Release requirements

Remaining release work: `.env.example` without secrets, Dockerfile, `.dockerignore`, Render blueprint if appropriate, migration/rollback instructions, staging configuration and tested backup/recovery. Do not use ephemeral app filesystem for permanent records. Do not deploy real student information to an insecure environment or incur paid infrastructure without authorization.

Configure `ENQUIRY_ADMIN_EMAILS` as a comma-separated allowlist of verified platform operator emails before using the contact enquiry inbox. Keep it separate from organization-level roles.
