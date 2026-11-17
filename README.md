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

The app, queue worker and scheduler share persistent SQLite storage in the `app-storage` volume. `docker compose -f compose.yaml -p pixelforge down` retains it; deleting the volume deletes accounts, image records and queued work. Rebuild after code changes. Run the focused suite with `docker compose -f compose.yaml -p pixelforge exec -u www-data app php artisan test`.

## Formatting

PHP uses Laravel Pint; frontend formatting uses pinned Prettier 3.6.2. From the project directory:

```sh
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/src -w /src --entrypoint php pixelforge-app /var/www/html/vendor/bin/pint --test .
docker run --rm -u "$(id -u):$(id -g)" -e NPM_CONFIG_CACHE=/tmp/npm-cache -v "$PWD":/app -w /app node:22-alpine sh -c 'npm ci --audit=false && npm run format:check'
```

Omit `--test` to apply PHP formatting; use `npm run format` to apply frontend formatting.

## Structure

Form Requests validate inputs and authorize retries through `GenerationPolicy`; controllers only coordinate HTTP responses. `AuthService` and `GenerationService` handle account and request workflows. `PricingService` calculates exact prices; `WalletService` owns reservations and settlement; `AdminReportService` reads daily accounting summaries and reconciles provider usage. `GenerationStateService` owns atomic status/billing transitions and webhook deduplication; `ProcessGeneration` queues one generation's next step through `GenerationWorkerService`. Concrete domain repositories contain persistence queries and row locks, and `PixelforgeClient` contains provider HTTP calls. History resources expose an explicit, stable response shape without internal retry or correlation fields.

Private Inertia history is encrypted and cleared on logout and guest authentication pages. Login attempts are limited by account/IP pair and by IP, so traffic from another IP cannot exhaust a user’s per-account allowance. Webhooks use a stateless route: signatures authenticate raw bytes before Form Request parsing. Query indexes support owner history, due work and pending events; scheduled recovery reads overdue work in bounded batches.

## Design

- A generation and its wallet reservation are saved before its Laravel job is dispatched after commit; its UUID is the immutable provider idempotency key and metadata correlation ID. Client request IDs prevent accidental repeat submissions.
- Jobs run one submission, poll or reconciliation step and release delayed work back to the queue. Shared locks prevent overlapping work for a generation, while database coordination allocates provider capacity and paces authenticated API requests across workers and keys. Network calls happen outside row-lock transactions. SQLite uses WAL, a busy timeout and immediate transactions; MySQL uses row locks. Both serialize webhook and worker updates.
- New submissions wait locally while three provider slots are reserved, without consuming retry attempts. Accepted or unconfirmed work retains its slot until resolved. Daily quota rejections stop immediately. Submissions use at most five automatic attempts with exponential backoff and `Retry-After`. Lost replies remain unconfirmed, never falsely free failures. After exhausted ambiguous submissions, bounded provider-history pagination reconciles the metadata ID. A visible “Check again” action safely resumes with the original key. Poll failures also pause after five errors; webhook delivery can still finish them.
- Webhook HMACs cover the raw body with a five-minute timestamp tolerance. Event IDs are unique; event insertion and state/billing updates commit together. The same monotonic updater handles webhooks, submission replies and polls. Terminal records cannot regress or be billed twice. Early events correlate through metadata; unmatched events remain stored for reconciliation.
- Pricing is fetched publicly and cached for 60 seconds. Estimates use Unicode character counts and midpoint reasoning tokens, with an expected range. Final billing uses all four actual token buckets and the generation's frozen rates, plus exactly 25%. BCMath and decimal strings preserve 18 decimal places, including fractional nanodollars; displayed amounts are not rounded to cents. Failed requests cost zero. Unknown outcomes remain unpriced until confirmed.
- Images use the provider's signed URLs and expire after 24 hours. No permanent image storage is included.

## Queue operations

The proof of concept requires Laravel's database queue on the **application's database connection** to keep infrastructure small and commit queued work with its durable receipt: `QUEUE_CONNECTION=database`, `CACHE_STORE=database` and `DB_QUEUE_RETRY_AFTER=90`. All app, worker and scheduler processes must share the same database and cache; local per-process caches cannot coordinate locks. Docker starts one worker and one scheduler with these commands:

```sh
php artisan queue:work --queue=generations --sleep=1 --tries=0 --timeout=45 --max-time=3600
php artisan schedule:work
```

`--tries=0` allows capacity waits and delayed steps to be released without exhausting Laravel's delivery count; the generation's persisted counters still bound provider submission and polling attempts. Keep the job/worker timeout below the queue's 90-second `retry_after`, and give the process manager at least 60 seconds to stop gracefully. Restart long-running workers after code/config changes; Docker recreates them and Cloud restarts its queue workers on deployment.

After admission commits, inserting the job and recording its UUID on the generation happen in one database transaction. That UUID survives queue releases. `pixelforge:recover` runs every minute and uses an indexed receipt lookup to requeue at most 100 overdue generations whose job is missing; existing queued, delayed or reserved jobs are skipped regardless of age. This repairs a crash between admission and enqueue without accumulating duplicate jobs while workers are stopped or busy. The scheduler is required even when webhooks are enabled.

One worker is enough for this proof of concept. To add workers, keep the same `generations` queue, shared database and shared cache. Docker supports `docker compose -f compose.yaml -p pixelforge up -d --scale worker=3`; use MySQL for a larger deployment rather than scaling SQLite writers indefinitely. The provider permits **60 authenticated requests per minute, three concurrent generations and 500 generations per day per account, across all API keys**. `PIXELFORGE_CONCURRENCY_LIMIT=3` and `PIXELFORGE_REQUEST_INTERVAL_MS=1000` bound this deployment's admission and pacing; lower concurrency or slower pacing can leave room for other clients. Separate environment databases cannot coordinate account-wide use. Separate keys help attribution, but do not increase these quotas; provider `429` responses and `Retry-After` remain authoritative.

For higher production throughput and queue operations, prefer [Redis with Laravel Horizon](https://laravel.com/framework/docs/12.x/horizon) for queue monitoring and worker management, or a managed message queue such as [Amazon SQS through Laravel's queue driver](https://laravel.com/framework/docs/12.x/queues). Moving this proof of concept to either backend requires adapting its database-specific job receipts and atomic enqueue ownership to an equivalent outbox/receipt mechanism; changing `QUEUE_CONNECTION` alone is unsupported. Changing transport also does not remove provider quotas, shared coordination or database/report capacity limits.

When upgrading from `pixelforge:work`, arrange a maintenance window and drain or resolve **every nonterminal generation, including paused, accepted and unconfirmed requests, until the count is zero**. Run `php artisan down`, recheck that count, and stop the old custom worker before migrations. Take a database backup and snapshot historical counts, statuses, exact costs and wallet balances/reservations. With writes still stopped, deploy the new code and queue/cache configuration and run `php artisan migrate --force`; this creates queue coordination and transactionally backfills daily report summaries. Standard workers must keep `--force` off while maintenance is active. Verify the historical snapshot is unchanged, summaries match the exact historical totals, and the queue configuration, worker and scheduler are correct before `php artisan up`. Keep old and new workers from overlapping. Later deployments must restart workers after migrations so they load the deployed code.

## Credits and reports

All accounts start with $0. The Studio offers simulated $5, $10 and $25 purchases, capped at a $1,000 balance; no payment is processed. Purchase UUIDs are unique per user, so retries credit once. New images reserve the maximum estimated cost under a user row lock; simultaneous requests cannot spend the same available funds. Terminal settlement locks the generation and then its user, debits the exact actual charge once, and releases the hold. Confirmed failures release it for free; uncertain or paused requests retain it. Existing pre-wallet generations remain exempt from wallet debits.

The worker refreshes prices before a definite submission and cancels locally for free if the revised hold is unaffordable. Accepted generations use their frozen rates. The provider has no locked quote or spending cap: a price change during acceptance can exceed the hold. The actual cost is still recorded exactly; a resulting negative available balance blocks new images until credits are added.

Grant access to an explicitly registered account (there is no automatic administrator):

```sh
docker compose -f compose.yaml -p pixelforge exec app php artisan pixelforge:admin person@example.com
# Add --revoke to remove access. On Cloud, run the artisan command in its console.
```

`/admin/reports` reads daily summaries of successful generation charges, provider costs and profit by UTC completion day, user and provider key. Each first successful settlement updates its summary in the same transaction, with exact 18-decimal accounting and no refresh delay or double counting from duplicate callbacks. The migration backfills historical successes in bounded batches; report requests do not rescan generation history. Date ranges are limited to 92 inclusive days. The default view and provider comparison use the configured API key; the all-history view preserves older key cohorts. Purchases fund wallets and never count as generation spend or profit. Provider comparisons are fetched on request; unavailable comparisons leave the local report visible. Unattributed historical rows and differences from external activity remain explicit.

Use separate provider keys per environment. New records store a SHA-256 fingerprint, never the key. Before rotating keys, drain pending requests and retain the old key's attribution for historical records; do not infer a legacy null fingerprint without verifying its origin. An ambiguous submission pauses if its original connection changes, retaining its hold.

## Local diagnostics

Telescope is a development dependency, registered only when `APP_ENV=local`. Docker uses that environment by default. Its migrations live in `database/migrations/local` and are loaded only with Telescope, so Cloud's production install creates no diagnostics tables or routes.

After granting your local account admin access with `pixelforge:admin`, open http://localhost:8088/telescope. Authentication and admin access are required even locally. Passwords, API authorization, cookies, CSRF tokens and webhook signatures are redacted. Standard Laravel queue jobs give Telescope a per-job storage/flush boundary, so worker activity is recorded locally. To clear entries older than 48 hours:

```sh
docker compose -f compose.yaml -p pixelforge exec app php artisan telescope:prune --hours=48
```

Keep `APP_ENV=production` and `composer install --no-dev` on Cloud. See the [Telescope local installation guide](https://laravel.com/framework/docs/telescope#local-only-installation).

## Laravel Cloud

1. Open [Laravel Cloud](https://cloud.laravel.com), connect the GitHub repository and choose the Laravel runtime and PHP 8.4. Cloud includes Node/npm, BCMath and PDO MySQL. Leave Octane and Inertia SSR off.
2. Attach a **MySQL** database in the same region. Cloud injects its `DB_*` connection variables. Do not copy the local SQLite `DB_DATABASE` value: Cloud filesystems are ephemeral and SQLite is unsupported.
3. For the proof of concept, use one App instance with autoscaling and scale-to-zero disabled. In **Background processes → New background process → Queue worker**, set connection **database**, queue **generations**, sleep **1 second**, timeout **45 seconds**, tries **1**, force **off** and **1 process**. Cloud's form rejects zero; `ProcessGeneration::$tries = 0` overrides this worker default, allowing delayed deliveries while its persisted provider-attempt counters remain bounded. Cloud restarts this worker on deployment; Docker's optional `--max-time=3600` recycling flag is not required in the Cloud form. Set the cluster's graceful shutdown timeout to at least **60 seconds**. Enable the App cluster's **Scheduler** toggle, save and deploy; Cloud then invokes `schedule:run` every minute. Alternatively, run `php artisan schedule:work` as one custom background process with the Scheduler toggle off. Redis, a managed queue and a separate worker cluster are optional for this proof of concept. See [Cloud workers](https://laravel.com/cloud/docs/workers) and [scheduled tasks](https://laravel.com/cloud/docs/scheduled-tasks).
4. Set these environment variables in Cloud, keeping its injected database variables and HTTPS `APP_URL`. Set a unique `APP_KEY` if one is not already present; generate it with the Docker command in the local setup above, and retain it across deployments:

```dotenv
APP_NAME=Pixelforge
APP_ENV=production
APP_DEBUG=false
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90
LOG_CHANNEL=stderr
LOG_LEVEL=warning
PIXELFORGE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=YOUR-DEPLOYED-ENVIRONMENT-KEY
PIXELFORGE_WEBHOOK_SECRET=YOUR-SIGNING-SECRET
PIXELFORGE_CONCURRENCY_LIMIT=3
PIXELFORGE_REQUEST_INTERVAL_MS=1000
```

Never upload `.env`; enter the secrets through Cloud settings. Override `APP_URL` only when selecting a custom primary domain, using its HTTPS origin. Keep the same `APP_KEY` across deployments. Cloud handles its HTTPS proxy; `TRUSTED_PROXIES` is available for other hosts with explicit proxy IPs.

For an existing Cloud installation, first apply `APP_MAINTENANCE_DRIVER=cache` and `APP_MAINTENANCE_STORE=database` to the currently deployed code, redeploying that old revision if needed, **before** entering maintenance. All releases and processes must retain the same database and cache prefix; keep `APP_NAME` unchanged when it supplies the default prefix. After the drain, snapshot and `down` steps above, deploy configuration changes against that same old remote revision: replace the custom worker with the standard queue worker with **force off**, enable Scheduler, and set `QUEUE_CONNECTION=database`. The standard worker checks maintenance before reading jobs, so it remains idle even before the queue tables exist. Then push/deploy the new code and run migrations. Confirm maintenance survived the release and verify history, summaries, configuration and processes before `php artisan up`.

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
