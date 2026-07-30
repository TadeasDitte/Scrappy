# Scrappy [Everything in this repo was done by Claude, i didnt want to spend the time to make this but wanted to have this]

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
appends each chunk on arrival, so the thread paints as the model types. While
nothing has arrived yet the assistant bubble counts up (`waiting for first
token… 4.2s`) — a cold model can take a while to produce its first byte, and
that should read as *working*, not *frozen*.

Two things keep a fast model from feeling slower in the browser than it is on
the wire:

- **Chunks are batched** (`OllamaClient::batched`). Ollama emits one JSON
  object per token; forwarding each individually costs a flush, a
  chunked-transfer frame and a re-render, several hundred times per reply.
  Batches flush at 512 bytes or every 50 ms, whichever comes first, which cut a
  measured 3000-token reply from ~800 frames to ~55 with identical bytes. The
  first token is always passed straight through, and anything slower than ~20
  tokens/second still streams token by token — a 400 ms/token model measures
  the same before and after.
- **The browser writes once per animation frame.** Incoming text lands in a
  plain buffer and is committed to the reactive thread on the next frame, and
  each message renders through its own component, so a token updates one bubble
  instead of the whole conversation.

> **Changing frontend code?** The Docker image bakes `npm run build` in at
> build time (`Dockerfile`), so `docker compose up -d` alone will keep serving
> the old bundle. Use `docker compose up -d --build`, or run `npm run dev` for
> Vite in front of a local server.

## Chat

The chat page keeps a history sidebar. The first prompt in a thread creates a
conversation titled from that prompt; both turns are stored, and the response
header `X-Conversation-Id` lets the open thread adopt its new id without an
extra round trip. Conversations can be renamed, deleted individually, or
cleared in bulk.

Follow-up prompts replay the stored transcript to the endpoint through Ollama's
`/api/chat`, so the model has context. A thread's very first message still goes
to `/api/generate`. Saved conversations always build that context from the
database rather than from anything the browser sends.

**Temporary mode** (the ghost button) streams a thread that is never written
down: no conversation row, no messages, nothing in the sidebar. Context is kept
client-side and sent with each prompt so the model still follows along, and a
`conversation_id` supplied alongside `temporary` is ignored. Toggling the mode
either way starts a clean thread, so a temporary chat can never inherit — or
leak into — a saved one.

Per-request generation options (system prompt, temperature, top P, max tokens,
seed, stop sequences, keep-alive) live behind **Options** in the thread header
and use the same validation as the API.

**Keep model loaded** sets Ollama's `keep_alive`. Hosts unload an idle model
after a few minutes, and the next message then pays for a full load from disk —
often the single biggest reason a follow-up feels far slower than the same
prompt run twice through `curl`. Setting it to something like `10m` keeps the
model resident. It is left unset by default: these are other people's machines,
and pinning a model in their memory should be a deliberate choice.

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
