# SDK / app contract

The SDK talks to the configured Manifest API using `Authorization: Bearer <project key>` and `User-Agent: mnfst-php/<version>`.

## Handshake

On install, once per process, the SDK announces itself with `POST /v1/hello`:

```json
{"runtime": "php-8.3.0"}
```

The SDK name and version ride in the `User-Agent`; the body is the runtime and nothing else. The server answers `{"status":"ok"}` and carries no project data. `manifest doctor` sends the same body with `"probe": true`: a key check the server answers without recording an install, so a run from a laptop never marks the app as connected. Without a project key there is no handshake and no capture: the SDK is inert. This is a separate endpoint from the requests ledger — a synthetic failing request would write data that never happened into the customer's Requests list and corrupt reported volume and recovery rate.

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

Every JSON map is encoded as an object even when empty (`"headers": {}`, never `[]`, which the server refuses as a list). Any 4xx response is captured except 401, 402, 403 and 429; those and every 5xx pass through untouched, because auth, billing, rate limiting and server faults are not repaired by editing the request. Credential filtering and body limits are described in the README. Capture gates live in `Gate.php`; the server owns repair policy.

A successful heal response may contain `status: patched|unverified`, `healAttemptId`, `operations` and `healedRequest` with `url`, `headers` or `body`. Only these two statuses authorize a retry. No patch, malformed responses and unavailable service return the original error response. HTTP 403 with `{"error":"project_disabled"}` suppresses healing for five minutes.

## Apply

The server heals the whole request — operations address the query string, headers and path as well as the body — and `healedRequest` carries every side. `Replay::plan` applies all of them: a healed URL replaces the URL only within the original origin and never with credentials in it; headers set or replace case-insensitively, null removes a header, and a value equal to the SDK's own mask (`REDACTED`) is never put on the wire. Content length is recalculated. Objects merge using the server's healed body as the authoritative copy of fields sent to the server; withheld local credential fields are restored. Non-object JSON replaces the body. A form-urlencoded request is replayed as a form with its field names kept verbatim (dots, spaces and brackets included) and a repeated name repeated; a non-object healed body is not retried for one. An unreadable or unparseable original body is not retried. A healed body that merges to nothing is sent as no body at all: GET, HEAD, DELETE and OPTIONS retry bodyless, and any other method is not retried.

Each captured failure permits one retry, sent through the same client instance with the caller's own options (handler stack, proxy, TLS, timeouts), so a faked or mocked client stays faked on the retry. A retry response, including another failure, is returned to the caller the way the original would have been: a Guzzle client with `http_errors` on gets the retry's `ClientException`, not a fulfilled 4xx. A transport failure returns the original response. Successful response streams are not eagerly consumed.

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

HTTP status must be 200–599. A failed retry carries its raw body, JSON or not, and whether it was cut at 64 KB. Failure messages have any URL masked like a captured URL and are cut at 512 UTF-8 bytes. HTTP status zero is not a wire status. A retry that never got an HTTP answer reports `transport_error`; one the SDK decided not to send reports `not_attempted`. Transport failures and unattempted retries are inconclusive evidence; neither can verify or invalidate a patch. The server determines the verdict from the raw evidence, with the first accepted report winning.

Reports are best effort and bounded at 5 seconds. The SDK sends the failed retry's raw body so the app can distinguish recurrence from a newly revealed issue. It does not assert `succeeded` or `failed` itself.
