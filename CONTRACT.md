# SDK / app contract

The SDK talks to the configured Manifest API using `Authorization: Bearer <project key>` and `User-Agent: mnfst-php/<version>`.

## Handshake

On install, once per process, the SDK announces itself with `POST /v1/hello`:

```json
{"runtime": "python-3.12.0"}
```

The SDK name and version ride in the `User-Agent`; the body is the runtime and nothing else. This is a separate endpoint from the requests ledger — a synthetic failing request would write data that never happened into the customer's Requests list and corrupt reported volume and recovery rate.

Best-effort and fire-and-forget: a handshake that fails is never retried and never surfaces to the app. Absence of a handshake is the signal ("not connected"), so the dashboard can tell an install that never loaded apart from a healthy app that has no failures.

## Capture

`POST /v1/heal` receives:

```json
{
  "traceId": "unique-capture-id",
  "request": {"method": "POST", "url": "https://example.com/orders", "headers": {}, "body": {"limit": 200}},
  "response": {"statusCode": 400, "body": {"error": "limit must be at most 100"}, "truncated": false},
  "responseTimeMs": 25
}
```

Any 4xx response is captured except 401, 402, 403 and 429; those and every 5xx pass through untouched, because auth, billing, rate limiting and server faults are not repaired by editing the request. Credential filtering and body limits are described in the README. Capture gates live in `Gate.php`; the server owns repair policy.

A successful heal response may contain `status: patched|unverified`, `healAttemptId`, `operations` and `healedRequest` with `url`, `headers` or `body`. Only these two statuses authorize a retry. No patch, malformed responses and unavailable service return the original error response. HTTP 403 with `{"error":"project_disabled"}` suppresses healing for five minutes.

## Apply

The retry is the original request sent again through the same client, with the same method, headers and options, and the healed parts swapped in. A healed URL replaces the URL only within the original origin. Headers set or replace case-insensitively; null removes a header. Query and header values the SDK masked as `REDACTED` are restored from the original request on the retry; a `REDACTED` with nothing to restore is not retried. A credential-named query parameter the original request carried and the healed URL omits is put back, since the server never saw its value. Fragments are dropped. Content length is recalculated. Objects merge using the server's healed body as the authoritative copy of fields sent to the server; withheld local credential fields are restored. Non-object JSON replaces the body. A form-urlencoded request is replayed as a form, re-encoded from the parsed structure, so repeated keys return as indexed keys; a non-object healed body is not retried for one. An unreadable or unparseable original body needs a replacement body before it can be retried. A healed body that merges to nothing is sent as no body at all: GET, HEAD, DELETE and OPTIONS retry bodyless, and any other method is not retried.

Each captured failure permits one retry. A retry response, including another failure, is returned to the caller. A transport failure returns the original response. Successful response streams are not eagerly consumed.

## Outcome

`PATCH /v1/heal-attempts/:id` sends exactly one of:

```json
{"response":{"statusCode":200}}
```

```json
{"response":{"statusCode":400,"body":{"error":"raw upstream error"},"truncated":false}}
```

```json
{"failure":{"kind":"transport_error","message":"connection reset"}}
```

```json
{"failure":{"kind":"not_attempted","message":"replay_not_attempted"}}
```

HTTP status must be 200–599. Failure messages are capped at 512 UTF-8 bytes after credential filtering. HTTP status zero is not a wire status. Transport failures and unattempted retries are inconclusive evidence; neither can verify or invalidate a patch. The server determines the verdict from the raw evidence, with the first accepted report winning.

Reports are best effort, bounded, and observable through PHP warnings. The SDK sends the failed retry's raw body so the app can distinguish recurrence from a newly revealed issue. It does not assert `succeeded` or `failed` itself.
