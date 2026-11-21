# Deployment

## Current status

No deployment credentials, approved domain or staging service is present. Nothing has been deployed. `.github/workflows/quality.yml` runs web typecheck/lint/build, Laravel tests/Pint, mobile typecheck/Android JS export, and a production API image build plus HTTP liveness smoke check on pushes and pull requests. These workflow checks do not deploy or need application secrets; the new container job has not run in hosted CI yet.

The API has a production-oriented Apache/PHP 8.4 container recipe in `apps/api/Dockerfile`. It installs PostgreSQL support, serves only Laravel's `public/` directory, writes Apache logs to standard output/error, excludes local environment files and SQLite databases from the build context, and listens on the runtime `PORT` (default 10000). It does not run migrations automatically. A CI job now builds the image and checks the liveness endpoint without publishing it or connecting a database. Docker is not installed in the current development environment, so the image and smoke check await a hosted workflow run; database readiness and migration behavior are not part of that smoke check.

## Website preview

The frontend builds to Vite's `dist/` directory and can be served as static assets. SPA route fallback must be configured by the eventual host. Set `EDTECHLIVE_SITE_URL` to the approved public HTTPS origin during the frontend build; the build then generates absolute canonical and Open Graph URLs for each pre-rendered public route, an absolute sitemap, and a matching `robots.txt` reference. Without a configured origin, the build omits the sitemap and canonical metadata instead of shipping a reserved placeholder domain. Confirm the origin is approved before launch.

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

Workspace event email is a separate, optional feature from authentication verification and password-reset mail. It is disabled by default (`WORKSPACE_EMAIL_NOTIFICATIONS=false`). Before enabling it, configure an approved production mail transport, set `QUEUE_CONNECTION=database` (or another durable supported queue), run the queue migration if needed, and keep a persistent worker running with `php artisan queue:work`. Users must explicitly opt in by category and have verified email addresses. Delivery status is visible to the recipient in their notification settings. The local `.env` log mailer is for development only and does not deliver to an inbox; do not enable workspace notifications against it expecting real delivery. No provider credentials are included in the repository.

## Expo mobile push

Push delivery is disabled by default. Link the mobile app to an Expo/EAS project, set its public `EXPO_PUBLIC_EAS_PROJECT_ID` for native builds, and configure the same `EXPO_PROJECT_ID` plus `EXPO_PUSH_NOTIFICATIONS=true` in the API. Add native Android/iOS push credentials and install development builds on real devices; Expo Go, the web preview and JavaScript bundle exports cannot verify remote push delivery. If enhanced push security is enabled in Expo, store its access token as `EXPO_ACCESS_TOKEN` in the API secret store. A persistent Laravel queue worker is required. Device tokens are encrypted at rest using Laravel `APP_KEY`, account-scoped, and removed when users opt out or Expo reports `DeviceNotRegistered`. Push text is intentionally generic; notification category preferences still control which events are eligible.

## Educator AI drafts

Optional teacher lesson-plan drafts use a locally hosted Ollama service and stay disabled unless `AI_DRIVER=ollama` and `AI_MODEL` are configured. Point `AI_BASE_URL` only at a private Ollama service reachable from the API host; this feature is designed for local/private inference and does not support external paid endpoints. It sends course title/subject/level and the educator's topic/objectives, not learner records or stored lesson content. Usage stores model, token counts and success/failure only and is scheduled for removal after 90 days with the Laravel scheduler. Keep the model endpoint private; no cloud AI provider or data-processing agreement is configured.

## Razorpay fee checkout (Test Mode only)

Checkout is disabled by default. To exercise it, configure `RAZORPAY_MODE=test`, an `rzp_test_...` `RAZORPAY_KEY_ID`, the matching `RAZORPAY_KEY_SECRET`, and a separate webhook secret in the API's secret environment. Never set live keys: the application rejects live-mode credentials. Configure the Razorpay Test Mode webhook URL as `https://<public-api-host>/api/v1/integrations/razorpay/webhook` with the `payment.captured` event and the same webhook secret. A public HTTPS API endpoint is required for provider webhooks; localhost cannot receive them. Use provider sandbox credentials and simulated cards only. No real charge, settlement, or refund is permitted by this implementation. Sandbox keys and real provider behavior have not been verified in this repository.

Render documents Docker support for PHP/Laravel, Blueprint configuration, and HTTP health checks. Its free services are intended for evaluation rather than production, so no free-plan Blueprint is included as a production deployment recipe: [Laravel with Docker](https://render.com/docs/deploy-php-laravel-docker), [Blueprint specification](https://render.com/docs/blueprint-spec), [health checks](https://render.com/docs/health-checks), and [free plan limitations](https://render.com/docs/free).

## Release requirements

Remaining release work: verify the CI container build/liveness result, test database readiness against PostgreSQL, decide whether to add a Render blueprint, rehearse migration/rollback, configure staging, and test backup/recovery. Do not use ephemeral app filesystem for permanent records. Do not deploy real student information to an insecure environment or incur paid infrastructure without authorization.

Configure `ENQUIRY_ADMIN_EMAILS` as a comma-separated allowlist of verified platform operator emails before using the contact enquiry inbox. Keep it separate from organization-level roles.
