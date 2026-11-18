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

The API exposes `GET /api/v1/health` as a liveness endpoint and `GET /api/v1/health/ready` as a database readiness probe. A hosting health check can use the readiness route; it returns a generic 503 if the configured database cannot answer a simple query.

## Configuration preflight

The Render target must use separate web/static and API origins unless a production reverse proxy is deliberately configured. Set `VITE_API_URL` to the HTTPS API origin at frontend build time, then set the API's `FRONTEND_URL`, exact `CORS_ALLOWED_ORIGINS`, and `SANCTUM_STATEFUL_DOMAINS` for the selected frontend host. Keep `APP_KEY` in the host secret store, set `APP_DEBUG=false`, use PostgreSQL, configure a real mail transport, and move private uploads from the local filesystem to durable private object storage before accepting real learner data. Run migrations only after PostgreSQL-specific validation and a backup/restore plan.

Render documents Docker support for PHP/Laravel, Blueprint configuration, and HTTP health checks. Its free services are intended for evaluation rather than production, so no free-plan Blueprint is included as a production deployment recipe: [Laravel with Docker](https://render.com/docs/deploy-php-laravel-docker), [Blueprint specification](https://render.com/docs/blueprint-spec), [health checks](https://render.com/docs/health-checks), and [free plan limitations](https://render.com/docs/free).

## Release requirements

Remaining release work: `.env.example` without secrets, Dockerfile, `.dockerignore`, Render blueprint if appropriate, migration/rollback instructions, staging configuration and tested backup/recovery. Do not use ephemeral app filesystem for permanent records. Do not deploy real student information to an insecure environment or incur paid infrastructure without authorization.

Configure `ENQUIRY_ADMIN_EMAILS` as a comma-separated allowlist of verified platform operator emails before using the contact enquiry inbox. Keep it separate from organization-level roles.
