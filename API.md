# Scrappy API v1

Base path `/api/v1`. Everything except the index requires a personal access
token, created at **Settings → API tokens**.

```bash
curl -H "Authorization: Bearer <token>" https://your-host/api/v1/domains
```

`GET /api/v1` returns this reference as JSON — endpoints, parameters and the
generation-option table are generated from the same definitions the validator
uses, so they cannot drift from the implementation.

## Abilities

A token carries one or both:

| Ability | Grants |
| --- | --- |
| `domains:read` | `GET` domains and models |
| `chat:generate` | `POST` completions |

A token missing the ability for an endpoint gets `403 Invalid ability provided.`

## Endpoints

### `GET /api/v1`

The endpoint catalog. No authentication.

### `GET /api/v1/domains`

Active domains, fastest first.

| Parameter | Default | Notes |
| --- | --- | --- |
| `sort` | `speed` | `speed` or `models` |
| `per_page` | `25` | |

```json
{
  "data": [
    {
      "id": 12,
      "host": "ollama.example.com",
      "base_url": "https://ollama.example.com",
      "is_active": true,
      "response_time_ms": 84,
      "model_count": 6,
      "last_active_at": "2026-07-28T14:02:11+00:00",
      "last_probed_at": "2026-07-28T14:02:11+00:00"
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" },
  "meta": { "current_page": 1, "per_page": 25, "total": 41 }
}
```

### `GET /api/v1/domains/{domain}`

One domain, with its available models embedded under `models`.

### `GET /api/v1/domains/{domain}/models`

The models a single domain currently serves. Unavailable models are omitted.

```json
{
  "data": [
    {
      "id": 91,
      "name": "llama3:8b",
      "family": "llama",
      "parameter_size": "8B",
      "quantization": "Q4_0",
      "size_bytes": 4661224676,
      "available": true
    }
  ]
}
```

### `GET /api/v1/models`

Search models across every active domain. Results are ordered by the response
time of the domain serving them, so the first hit is the fastest way to reach
that model. Each entry embeds its `domain`.

| Parameter | Notes |
| --- | --- |
| `search` | Substring of the model name |
| `family` | Exact family, e.g. `llama` |
| `parameter_size` | Exact size, e.g. `8B` |
| `per_page` | Default `25` |

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://your-host/api/v1/models?search=llama3&per_page=5"
```

### `POST /api/v1/chat/generate`

Single-prompt completion.

| Field | Required | Notes |
| --- | --- | --- |
| `domain_id` | yes | Must be an active domain |
| `model` | yes | Must be served by that domain |
| `prompt` | yes | Max 8000 characters |
| `system` | no | Max 4000 characters |
| `stream` | no | Default `true` |
| `options` | no | See [Generation options](#generation-options) |

Streamed (default) — the body is `text/plain`, written as the model produces it:

```bash
curl -N -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"domain_id":12,"model":"llama3:8b","prompt":"Say hi","options":{"temperature":0.2}}' \
     https://your-host/api/v1/chat/generate
```

Blocking — `"stream": false` returns one JSON document with timing metrics:

```json
{
  "data": {
    "domain": { "id": 12, "host": "ollama.example.com" },
    "model": "llama3:8b",
    "response": "Hi there.",
    "done_reason": "stop",
    "metrics": {
      "total_duration_ms": 1500,
      "load_duration_ms": 12,
      "prompt_eval_count": 7,
      "eval_count": 10,
      "tokens_per_second": 20
    }
  }
}
```

Any metric Ollama omits comes back as `null`.

### `POST /api/v1/chat`

Multi-turn conversation. Same fields as `chat/generate`, except `prompt` is
replaced by `messages`:

| Field | Required | Notes |
| --- | --- | --- |
| `messages` | yes | 1–100 items |
| `messages[].role` | yes | `system`, `user` or `assistant` |
| `messages[].content` | yes | Max 8000 characters |

If `system` is also supplied it is prepended to `messages` as a system turn.
Streams by default, `"stream": false` for the blocking form.

```bash
curl -N -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "domain_id": 12,
       "model": "llama3:8b",
       "system": "Be terse.",
       "messages": [
         { "role": "user", "content": "Name three primes." },
         { "role": "assistant", "content": "2, 3, 5." },
         { "role": "user", "content": "The next three?" }
       ]
     }' \
     https://your-host/api/v1/chat
```

## Generation options

Passed as an `options` object on either chat endpoint and forwarded to Ollama.
Every key is optional; unknown keys are rejected rather than silently dropped,
with an error naming what is supported.

| Option | Type | Range |
| --- | --- | --- |
| `temperature` | number | 0 – 2 |
| `top_p` | number | 0 – 1 |
| `top_k` | integer | 1 – 200 |
| `min_p` | number | 0 – 1 |
| `repeat_penalty` | number | 0 – 2 |
| `repeat_last_n` | integer | -1 – 2048 |
| `presence_penalty` | number | -2 – 2 |
| `frequency_penalty` | number | -2 – 2 |
| `num_predict` | integer | -1 – 8192 (-1 = unlimited) |
| `num_ctx` | integer | 128 – 131072 |
| `seed` | integer | any |
| `stop` | array | up to 4 strings, 64 characters each |

```json
{
  "options": {
    "temperature": 0.2,
    "num_predict": 256,
    "seed": 42,
    "stop": ["\n\n", "###"]
  }
}
```

## Errors

| Status | When |
| --- | --- |
| `401` | Missing or invalid token |
| `403` | Token lacks the required ability |
| `404` | Unknown domain |
| `422` | Validation failed — inactive domain, model not served by that domain, out-of-range or unknown option |
| `502` | Endpoint unreachable (blocking requests only) |

A `422` uses the standard Laravel shape:

```json
{
  "message": "Unsupported generation option(s): mirostat_wizardry. Supported: temperature, top_p, …",
  "errors": { "options": ["Unsupported generation option(s): …"] }
}
```

Streamed requests commit to `200` before the upstream call begins, so a failure
mid-stream cannot change the status code. It is appended to the body instead:

```
Once upon a time
[stream error: cURL error 28: Operation timed out]
```

Clients reading a stream should treat a trailing `[stream error: …]` as a
failed generation.

## Other routes

`GET /api/user` returns the authenticated user. It needs a valid token but no
particular ability.
