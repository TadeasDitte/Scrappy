# Scrappy

Scrappy discovers public Ollama endpoints, checks which ones are actually
answering, ranks them by response time, and gives you a chat UI and a token
authenticated API for talking to any of them.

Laravel 13 · Inertia v3 · Vue 3 · Tailwind v4 · Postgres · Redis.

## How it works

```
netint.xyz ──scrape:netint──▶ domains ──ProbeDomain job──▶ GET https://host/api/tags
                                 │                                │
                                 │                                ├─ 200 + models  → is_active, response_time_ms, models synced
                                 │                                └─ anything else → inactive, consecutive_failures++
                                 ▼
                        chat UI + /api/v1  ──▶ POST https://host/api/generate (NDJSON, streamed through)
```

| Piece | Where |
| --- | --- |
| Scraper | `App\Services\NetintScraper` — parses hostnames out of the netint listing |
| Probe | `App\Jobs\ProbeDomain` (queued) via `App\Services\OllamaClient::tags()` |
| Ollama transport | `App\Services\OllamaClient` — tags, generate, chat, streamed and blocking |
| Web chat | `App\Http\Controllers\ChatController` → `resources/js/pages/Chat.vue` |
| Public API | `App\Http\Controllers\Api\*`, routes in `routes/api.php` |

Discovery and health checks run on a schedule (`routes/console.php`):

- `scrape:netint` — daily; finds new hostnames and queues probes for them.
- `domains:probe` — hourly; re-checks active and never-probed domains.
- `domains:probe --all` — daily at 03:00; full re-probe, which also revives
  hosts that came back.

Both commands can be run by hand:

```bash
php artisan scrape:netint            # --no-probe to sync domains only
php artisan domains:probe            # --only-active | --all
```

The Domains page also has a **Refresh now** button that queues the same work.

## Running it

### Docker (Postgres, Redis, queue worker and scheduler included)

```bash
cp .env.example .env

# Put an APP_KEY in .env — locally, or from a throwaway container:
php artisan key:generate
# docker compose run --rm app php artisan key:generate --show   # then paste it in

docker compose up -d --build
```

That is the whole setup: the `app` container's entrypoint waits for Postgres,
runs `migrate --force` and warms the config/event caches on boot, so there is
no separate migrate step. The app is then on `http://localhost:${APP_PORT}`
(`80` in `.env.example`, `8000` if the variable is unset).

Compose runs six services: `app` (php-fpm), `nginx`, `postgres`, `redis`,
`worker` (`WORKER_REPLICAS`, default 2, on the Redis queue) and `scheduler`
(`schedule:work`). Database and Redis settings come from the `x-app-env`
anchor in `docker-compose.yml` and deliberately override `.env`, so containers
always talk to the compose services no matter what your local file points at.

Compose reads `.env` from the host for everything else — `APP_KEY` included —
so it must exist before `up`. Route caching is intentionally skipped in the
entrypoint: a few routes are closures and cannot be serialized.

### Local

```bash
composer setup     # install, .env, key, migrate, npm install, npm run build
composer dev       # serve + queue + logs + vite
```

`composer dev` runs the server, queue worker, log tail and Vite together — the
worker matters, since probes are queued.

Outside Docker the app uses whatever `.env` says. `.env.example` ships
`DB_CONNECTION=sqlite` with `DB_DATABASE=scrappy`, which SQLite reads as a file
path; either point `DB_DATABASE` at an absolute `.sqlite` file (and `touch` it)
or set the `pgsql` credentials for a local Postgres.

### Configuration

Beyond the standard Laravel keys:

| Variable | Default | Purpose |
| --- | --- | --- |
| `NETINT_SCRAPE_URL` | netint listing URL | Where hostnames are scraped from |
| `OLLAMA_PROBE_TIMEOUT` | `8` | Seconds allowed for `/api/tags` |
| `OLLAMA_GENERATE_TIMEOUT` | `120` | Seconds allowed for a completion |
| `PROBE_CONCURRENCY` | `20` | Probe jobs dispatched per batch |
| `OLLAMA_USER_AGENT` | `Scrappy/1.0 …` | Sent on every outbound request |
| `APP_PORT` | `80` | Host port nginx binds to |
| `WORKER_REPLICAS` | `2` | Queue worker containers |

## Streaming

Completions are proxied straight through: Ollama's newline-delimited JSON is
decoded chunk by chunk and the text is re-emitted as `text/plain`.

Two things keep tokens flowing instead of arriving in one lump at the end:

1. The controllers **return a `Generator`** from `response()->stream()`.
   Laravel flushes the output buffer between yields; a plain `echo` inside the
   closure does not, and the browser sees nothing until the request completes.
2. nginx has `fastcgi_buffering off` for PHP requests (`docker/nginx/default.conf`),
   and responses carry `X-Accel-Buffering: no`.

The chat UI consumes this with `useStream` from `@laravel/stream-vue` and
appends each chunk on arrival, so the thread paints as the model types.

## API

Token-authenticated JSON API under `/api/v1`, documented in **[API.md](API.md)**.
`GET /api/v1` returns the same reference as JSON, so the API describes itself:

```bash
curl https://your-host/api/v1
```

Create tokens at **Settings → API tokens**. Abilities are `domains:read` and
`chat:generate`.

## Testing and checks

```bash
php artisan test --compact     # 78 Pest tests
composer test                  # pint + phpstan + tests
composer ci:check              # the above plus eslint, prettier, vue-tsc
```

Individual tools: `vendor/bin/pint`, `vendor/bin/phpstan analyse`,
`npm run lint`, `npm run format`, `npm run types:check`.

## A note on scope

Scrappy aggregates endpoints that are already publicly reachable. It reads
`/api/tags` and forwards prompts you type. Point it at hosts you are allowed
to use.
