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

A successful heal response may contain `status: patched|unverified`, `healAttemptId`, `operations` and `healedRequest` with `url`, `headers` or `body`. Only these two statuses authorize a retry. No patch, malformed responses and unavailable service return the original error response. HTTP 403 with `{"error":"project_disabled"}` and HTTP 401 suppress healing for five minutes; a timeout, a transport failure or a 5xx suppresses it for one minute. The SDK remembers this across requests.

## Apply

The server heals the whole request — operations address the query string, headers and path as well as the body — and `healedRequest` carries every side. `Replay::plan` applies all of them: a healed URL replaces the URL only within the original origin and never with credentials in it; headers set or replace case-insensitively, null removes a header, and a value equal to the SDK's own mask (`REDACTED`) is never put on the wire. A masked query value is restored from the original request, and a credential-named query parameter the original carried and the healed URL omits is put back, since the server never saw its value; a mask with nothing to restore is not retried. Fragments are dropped. Content length is recalculated. Objects merge using the server's healed body as the authoritative copy of fields sent to the server; withheld local credential fields are restored. Non-object JSON replaces the body. A form-urlencoded request is replayed as a form with its field names kept verbatim (dots, spaces and brackets included) and a repeated name repeated; a non-object healed body is not retried for one. An unreadable or unparseable original body is not retried. A healed body that merges to nothing is sent as no body at all: GET, HEAD, DELETE and OPTIONS retry bodyless, and any other method is not retried.

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

## Tracked requests

Every call a hook sees and does not send to `POST /v1/heal`, whatever its status (2xx, 3xx, 401, 402, 403, 429, 5xx, and a 4xx while healing is paused), is recorded and sent in batches to `POST /v1/requests`:

```json
{"requests":[{"traceId":"a3ebec173ba83875ad12658ef5f0e115","method":"GET","url":"https://api.example.com/orders/42","statusCode":200,"responseTimeMs":80,"occurredAt":"2026-09-23T11:23:50.790Z"}]}
```

Metadata only. The URL carries scheme, host, port and path: no query string, userinfo or fragment. No headers and no request or response body are sent. Methods are upper-cased; a record whose method exceeds 16 characters or whose URL exceeds 4,096 is not sent. A call sent to `/v1/heal`, a heal's retry and the SDK's own calls are not tracked. Each call is recorded once, by the hook of the client that made it (raw curl skips calls made through Guzzle, Cake, Symfony or WordPress). A Symfony response is recorded when the app reads its status.

PHP keeps nothing between web requests, so calls are not batched in memory. Recording appends one JSON line, under an exclusive lock, to a spool file in the temp directory shared by every PHP process on the server (`mnfst-requests-<hash>.jsonl`, mode `0600`, capped at 2 MB; past the cap, calls are dropped). Nothing reaches the network on the caller's path.

Sending happens at shutdown, from a function that runs after the app's own shutdown functions. A process sends only when the last send is at least one second old and the spool holds about 500 calls or the last send is at least five seconds old; it claims the spool with an atomic `rename()`, so one process sends at a time. Under php-fpm it first calls `fastcgi_finish_request()`, so the user's response is already complete; on AWS Lambda it does not, because the container would freeze mid-send. Batches hold up to 500 calls within a total budget of two seconds (half a second on Lambda). A network error, timeout, 429 or 5xx is retried once if the budget allows; any other answer, including 404 from a server without the route, is final. A `project_disabled` 403 or a 401 pauses sending like healing. A claimed spool left by a process that died mid-send is removed after 60 seconds.

Known limits: a long-running process (Laravel Octane, RoadRunner, Swoole, `queue:work`) reaches shutdown only when a worker restarts, so its calls are sent then. Under mod_php, which has no `fastcgi_finish_request()`, the process that sends holds its connection for up to two seconds, at most once a second per server. An unwritable temp directory turns tracking off; healing is unaffected.

## Runtime behavior

All supported clients share the same capture, planning, outcome, and callback flow.
Raw curl uses that flow without a replay sender and closes any served attempt as
`not_attempted`. `onHeal` receives a masked URL and the result after each captured
failure; callback exceptions are logged and cannot escape into application code.
Manifest API answers are read with a 1 MB limit.

PHPUnit and Pest are silent by default, including standard runner commands loaded
through `auto_prepend_file`. `MNFST_IN_TESTS=1` explicitly enables integration-test
capture. Repeated installation refreshes configuration without duplicate hooks or
warnings. Clearing the key disables existing hooks as well as new installations.
