# Deployment

## Current status

No deployment credentials, approved domain or staging service is present. Nothing has been deployed. `.github/workflows/quality.yml` runs web typecheck/lint/build, Laravel tests/Pint, and mobile typecheck/Android JS export on pushes and pull requests. It does not deploy or need application secrets.

The API now has a production-oriented Apache/PHP 8.4 container recipe in `apps/api/Dockerfile`. It installs PostgreSQL support, serves only Laravel's `public/` directory, writes Apache logs to standard output/error, excludes local environment files and SQLite databases from the build context, and listens on the runtime `PORT` (default 10000). It does not run migrations automatically. Docker is not installed in the current development environment, so image build and container health have not been verified.

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

The Render target must use separate web/static and API origins unless a production reverse proxy is deliberately configured. Set `VITE_API_URL` to the HTTPS API origin at frontend build time, then set the API's `FRONTEND_URL`, exact `CORS_ALLOWED_ORIGINS`, and `SANCTUM_STATEFUL_DOMAINS` for the selected frontend host. Keep `APP_KEY` in the host secret store, set `APP_DEBUG=false`, configure `PLATFORM_ADMIN_EMAILS` and `ENQUIRY_ADMIN_EMAILS` only for verified operators, use PostgreSQL, configure a real mail transport, and move private uploads from the local filesystem to durable private object storage before accepting real learner data. Run migrations only after PostgreSQL-specific validation and a backup/restore plan.

For the API Docker service, build from `apps/api/Dockerfile`, set the health check path to `/api/v1/health/ready`, and provide a private PostgreSQL connection plus production Laravel environment variables. Run `php artisan migrate --force` as a controlled pre-deploy/release command after validating backups; the container startup never migrates a database. Deploy the web frontend as a static site with `npm ci && npm run build`, publish `dist/`, configure SPA route fallback, and set `VITE_API_URL` during the build. Do not accept real learner uploads until a durable private object-storage disk is configured.

Render documents Docker support for PHP/Laravel, Blueprint configuration, and HTTP health checks. Its free services are intended for evaluation rather than production, so no free-plan Blueprint is included as a production deployment recipe: [Laravel with Docker](https://render.com/docs/deploy-php-laravel-docker), [Blueprint specification](https://render.com/docs/blueprint-spec), [health checks](https://render.com/docs/health-checks), and [free plan limitations](https://render.com/docs/free).

## Release requirements

Remaining release work: build and inspect the Docker image, decide whether to add a Render blueprint, rehearse migration/rollback, configure staging, and test backup/recovery. Do not use ephemeral app filesystem for permanent records. Do not deploy real student information to an insecure environment or incur paid infrastructure without authorization.

Configure `ENQUIRY_ADMIN_EMAILS` as a comma-separated allowlist of verified platform operator emails before using the contact enquiry inbox. Keep it separate from organization-level roles.
