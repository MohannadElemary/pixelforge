# Pixelforge

A Laravel 12, Vue 3 and Inertia image studio. Sign up, add simulated credits, estimate an image and follow its status and exact cost in your collection. Administrators can reconcile daily spending with provider usage.

## Run

Docker and Docker Compose are the only requirements.

```sh
cp .env.example .env
# Generate APP_KEY and paste the result into .env:
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
docker compose -f compose.yaml -p pixelforge up -d --build
```

Open http://localhost:8088 and create an account. Add `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` to `.env`, then run `docker compose -f compose.yaml -p pixelforge up -d --force-recreate`. Without a key, live estimates still work and creation is visibly disabled. No sample results are simulated.

Register `https://YOUR_HOST/webhooks/pixelforge` on the provider key. Local webhooks need a public tunnel. Polling works without a tunnel. Use a separate key per environment.

Both services share persistent SQLite storage in the `app-storage` volume. `docker compose -f compose.yaml -p pixelforge down` retains it; deleting the volume deletes accounts and image records. Rebuild after code changes. Run the focused suite with `docker compose -f compose.yaml -p pixelforge exec -u www-data app php artisan test`.

## Formatting

PHP uses Laravel Pint; frontend formatting uses pinned Prettier 3.6.2. From the project directory:

```sh
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/src -w /src --entrypoint php pixelforge-app /var/www/html/vendor/bin/pint --test .
docker run --rm -u "$(id -u):$(id -g)" -e NPM_CONFIG_CACHE=/tmp/npm-cache -v "$PWD":/app -w /app node:22-alpine sh -c 'npm ci --audit=false && npm run format:check'
```

Omit `--test` to apply PHP formatting; use `npm run format` to apply frontend formatting.

## Structure

Form Requests validate inputs and authorize retries through `GenerationPolicy`; controllers only coordinate HTTP responses. `AuthService` and `GenerationService` handle account and request workflows. `PricingService` calculates exact prices; `WalletService` owns reservations and settlement; `AdminReportService` aggregates daily accounting and reconciles provider usage. `GenerationStateService` owns atomic status/billing transitions and webhook deduplication; `GenerationWorkerService` owns submission and recovery decisions. Concrete domain repositories contain persistence queries and row locks, and `PixelforgeClient` contains provider HTTP calls. History resources expose an explicit, stable response shape without internal retry or correlation fields.

Private Inertia history is encrypted and cleared on logout and guest authentication pages. Login attempts are limited by account/IP pair and by IP, so traffic from another IP cannot exhaust a user’s per-account allowance. Webhooks use a stateless route: signatures authenticate raw bytes before Form Request parsing. Query indexes support owner history, due work and pending events; capacity checks read at most three IDs and event recovery streams in batches.

## Design

- A generation is saved before any provider call; its UUID is the immutable provider idempotency key and metadata correlation ID. Client request IDs prevent accidental repeat submissions.
- The worker claims durable rows under a short database transaction. Network calls happen outside it. SQLite uses WAL, a busy timeout and immediate transactions. MySQL uses row locks; both serialize webhook and worker updates. One worker is sufficient for the provider's three-generation concurrency limit.
- New submissions wait locally while three known provider generations are active, without consuming retry attempts. Daily quota rejections stop immediately. Submissions use at most five automatic attempts with exponential backoff and `Retry-After`. Lost replies remain unconfirmed, never falsely free failures. After exhausted ambiguous submissions, bounded provider-history pagination reconciles the metadata ID. A visible “Check again” action safely resumes with the original key. Poll failures also pause after five errors; webhook delivery can still finish them.
- Webhook HMACs cover the raw body with a five-minute timestamp tolerance. Event IDs are unique; event insertion and state/billing updates commit together. The same monotonic updater handles webhooks, submission replies and polls. Terminal records cannot regress or be billed twice. Early events correlate through metadata; unmatched events remain stored for reconciliation.
- Pricing is fetched publicly and cached for 60 seconds. Estimates use Unicode character counts and midpoint reasoning tokens, with an expected range. Final billing uses all four actual token buckets and the generation's frozen rates, plus exactly 25%. BCMath and decimal strings preserve 18 decimal places, including fractional nanodollars; displayed amounts are not rounded to cents. Failed requests cost zero. Unknown outcomes remain unpriced until confirmed.
- Images use the provider's signed URLs and expire after 24 hours. No permanent image storage is included.

## Credits and reports

All accounts start with $0. The Studio offers simulated $5, $10 and $25 purchases, capped at a $1,000 balance; no payment is processed. Purchase UUIDs are unique per user, so retries credit once. New images reserve the maximum estimated cost under a user row lock; simultaneous requests cannot spend the same available funds. Terminal settlement locks the generation and then its user, debits the exact actual charge once, and releases the hold. Confirmed failures release it for free; uncertain or paused requests retain it. Existing pre-wallet generations remain exempt from wallet debits.

The worker refreshes prices before a definite submission and cancels locally for free if the revised hold is unaffordable. Accepted generations use their frozen rates. The provider has no locked quote or spending cap: a price change during acceptance can exceed the hold. The actual cost is still recorded exactly; a resulting negative available balance blocks new images until credits are added.

Grant access to an explicitly registered account (there is no automatic administrator):

```sh
docker compose -f compose.yaml -p pixelforge exec app php artisan pixelforge:admin person@example.com
# Add --revoke to remove access. On Cloud, run the artisan command in its console.
```

`/admin/reports` groups successful generation charges, provider costs and profit by UTC completion day and user. Date ranges are limited to 92 inclusive days. The default view and provider comparison use the configured API key; the all-history view preserves older key cohorts. Purchases fund wallets and never count as generation spend or profit. Provider comparisons are fetched on request; unavailable comparisons leave the local report visible. Unattributed historical rows and differences from external activity remain explicit.

Use separate provider keys per environment. New records store a SHA-256 fingerprint, never the key. Before rotating keys, drain pending requests and retain the old key's attribution for historical records; do not infer a legacy null fingerprint without verifying its origin. An ambiguous submission pauses if its original connection changes, retaining its hold.

## Local diagnostics

Telescope is a development dependency, registered only when `APP_ENV=local`. Docker uses that environment by default. Its migrations live in `database/migrations/local` and are loaded only with Telescope, so Cloud's production install creates no diagnostics tables or routes.

After granting your local account admin access with `pixelforge:admin`, open http://localhost:8088/telescope. Authentication and admin access are required even locally. Passwords, API authorization, cookies, CSRF tokens and webhook signatures are redacted. The long-running image worker is excluded from recording because it has no per-job flush boundary. To clear entries older than 48 hours:

```sh
docker compose -f compose.yaml -p pixelforge exec app php artisan telescope:prune --hours=48
```

Keep `APP_ENV=production` and `composer install --no-dev` on Cloud. See the [Telescope local installation guide](https://laravel.com/framework/docs/telescope#local-only-installation).

## Laravel Cloud

1. Open [Laravel Cloud](https://cloud.laravel.com), connect the GitHub repository and choose the Laravel runtime and PHP 8.4. Cloud includes Node/npm, BCMath and PDO MySQL. Leave Octane and Inertia SSR off.
2. Attach a **MySQL** database in the same region. Cloud injects its `DB_*` connection variables. Do not copy the local SQLite `DB_DATABASE` value: Cloud filesystems are ephemeral and SQLite is unsupported.
3. Use one App instance with autoscaling and scale-to-zero disabled. In **Background processes → New background process → Custom worker**, set `php artisan pixelforge:work` with **1 process**. No managed queue, scheduler, Redis or separate worker cluster is needed for this workload.
4. Set these environment variables in Cloud, keeping its injected database variables and HTTPS `APP_URL`. Set a unique `APP_KEY` if one is not already present; generate it with the Docker command in the local setup above, and retain it across deployments:

```dotenv
APP_NAME=Pixelforge
APP_ENV=production
APP_DEBUG=false
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=sync
LOG_CHANNEL=stderr
LOG_LEVEL=warning
PIXELFORGE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=YOUR-DEPLOYED-ENVIRONMENT-KEY
PIXELFORGE_WEBHOOK_SECRET=YOUR-SIGNING-SECRET
```

Never upload `.env`; enter the secrets through Cloud settings. Override `APP_URL` only when selecting a custom primary domain, using its HTTPS origin. Keep the same `APP_KEY` across deployments. Cloud handles its HTTPS proxy; `TRUSTED_PROXIES` is available for other hosts with explicit proxy IPs.

Build commands:

```sh
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --audit=false
npm run build
php artisan optimize
```

Deploy command: `php artisan migrate --force`. Use `/up` as the health-check path. Deploy, [create a Pixelforge account](https://pixelforge.promptedit.dev/register) and a key for this environment, then set that key's webhook URL to `https://YOUR-APP.laravel.cloud/webhooks/pixelforge` and copy its signing secret into Cloud. Redeploy after changing environment variables.

Sign up on the deployed app and try `[succeed]`, `[fail]` and `[duplicate]` prompts. Confirm completion, zero-cost failures, and a single charge per successful image. Use a distinct provider API key for the deployed environment.

See the official [Laravel deployment guide](https://laravel.com/cloud/docs/deploy-guides/laravel), [custom workers](https://laravel.com/cloud/docs/workers), and [SQLite limitation](https://laravel.com/cloud/docs/knowledge-base/sqlite). Public repository publication, Cloud provisioning and provider credentials are separate from the local build.
