# SDK guide

## Configuration

`manifest()` takes three optional arguments. The key and URL can come from
the environment; the callback is supplied in code.

| Argument | Environment | Default |
| --- | --- | --- |
| `$apiKey` | `MNFST_KEY` | none — without it nothing is sent |
| `$url` | `MNFST_URL` | `https://api.manifest.build` |
| `$onHeal` | — | none |

Environment variables are read from `$_SERVER`, `$_ENV` and `getenv()`, in that
order, so a key set in a Laravel or Symfony `.env` file is found without any
code. Passing the key explicitly (from `config()`, say) always wins. An empty
or whitespace-only key disables capture, including hooks already installed.
An explicitly blank environment value also overrides lower-priority sources.

Everything that is policy — whether a given app, endpoint or direction gets
healed — lives server-side, where it is editable without a deploy.

`$onHeal` is called after every captured failure with a `Mnfst\HealEvent`:
`url` (credentials masked), `statusCode` (the failure), `healStatus`
(`patched`, `unverified`, `no_patch`, `heal_unreachable` or `replay_failed`),
`replayStatusCode` (null when nothing was retried), `healMs` and `operations`.
A callback that throws is caught and logged; it cannot change the caller's response.

```php
use Mnfst\HealEvent;
use function Mnfst\manifest;

manifest(onHeal: fn (HealEvent $e) => error_log("[manifest] $e->healStatus → $e->replayStatusCode"));
```

## Calling manifest() more than once

`manifest()` is idempotent. Hooks are process-global and installed once; a
later call only refreshes the key, URL and callback they use, and never
warns. A framework that boots the application several times per process (a
test runner, Octane) or an `auto_prepend_file` next to a bootstrap call is fine.

## Testing

The SDK detects a PHPUnit or Pest run and installs no hooks, so a suite that
fakes its HTTP (`Http::fake()`, Guzzle's `MockHandler`, Symfony's
`MockHttpClient`) never reports those faked 4xx to Manifest. Nothing to
configure. Set `MNFST_IN_TESTS=1` when you do want healing during tests, e.g.
integration tests against a staging server. Standard PHPUnit, Pest, and
`artisan test` commands are recognized before bootstrap, including prepend
installs. Capture also checks the runner at request time, so a hook installed
before a custom test bootstrap becomes silent once the runner starts.

## Loading order

A PHP hook cannot attach to a function that has already been called in the
process. The SDK therefore has to load before your application makes its first
HTTP call, and `auto_prepend_file` is the only way to guarantee that.

Under php-fpm this matters more than it first appears: a worker process serves
many requests, so a single request that ran before the SDK loaded can leave that
worker uninstrumented for every later request it serves.

`vendor/bin/manifest doctor` reports whether `auto_prepend_file` is set.

## Verifying the installation

```sh
vendor/bin/manifest doctor
```

It prints the SDK version, the masked key, the coverage level, whether the SDK
loads early enough, and whether the server accepts the key. It exits non-zero
when installation checks fail. The probe does not record an installation.

## Laravel

Call `manifest()` from a service provider's `register()` method, passing the
key and URL from `config()` (Laravel's `.env` is read into `$_ENV`/`$_SERVER`,
which the SDK also reads, but config is the Laravel way and survives
`config:cache`). Every request through the `Http` facade is covered; a healed
call fires one `ResponseReceived` event, with the healed response.

The SDK installs nothing under a PHPUnit or Pest run, so `Http::fake()` answers
are never reported as real failures; you do not need to guard the call
yourself. Set `MNFST_IN_TESTS=1` to opt back in for integration tests that hit
a real server. (A `phpunit.xml` `<env name="MNFST_KEY" value=""/>` would not
have worked under `php artisan test` anyway: the artisan process hands its
`$_SERVER`, `.env` values included, to PHPUnit as the real environment, and
`<env>` never touches `$_SERVER`, which Laravel's `env()` reads first.)

`Http::retry()` retries a failed call; each attempt that fails is a capture of
its own, so a failure Manifest cannot fix is reported once per attempt.

## Supported traffic

Guzzle (every client in the process, whoever constructed it), Laravel's `Http`
facade, `Cake\Http\Client` and `Symfony\Component\HttpClient` (the curl and
native transports, and PSR-18 clients that run on one of these), plus
WordPress's `wp_remote_*` calls through `WpOrg\Requests`, are
instrumented and healed. A library with its own raw `curl_*` client is captured
but never healed. `file_get_contents` and other stream-based HTTP are not covered.

Symfony's responses are lazy and its `stream()` reads several at once for
concurrency; the SDK heals a response when the app first reads it, and leaves
`stream()` alone, so responses read only through streaming are not healed.
Explicitly unbuffered responses (`buffer => false`) are also left untouched.
Streaming a response after it has healed streams the retry, with the original
response object preserved as the iterator key.

## Limits and failure behaviour

- **Heal timeout: 10 seconds, not configurable.** A PHP heal runs inside a web
  request, and a web server commonly cuts the request off at 30 seconds. A
  longer heal would turn a fixable 400 into a 504.
- **Outcome report timeout: 5 seconds.** It runs after the retry answered, still
  inside the web request.
- **One retry per captured failure.** The retry's response, including another
  failure, is returned to the caller — thrown, if the client was configured to
  throw on 4xx. The retry goes through the same client instance with the same
  options, so a faked client in a test suite stays faked.
- **No key, no traffic.** Without `MNFST_KEY` the SDK installs nothing and
  sends nothing; `vendor/bin/manifest doctor` reports the missing key.
- **Fail open.** Any error inside the SDK returns the caller's original
  response. Callback and capture errors are caught inside the SDK.
- **Request bodies over 256 KB are not parsed**, and response bodies travel
  capped at 64 KB. Capturing a PSR-7 response reads at most 64 KB plus one byte
  to detect truncation; the caller can still read the entire body. A successful
  retry's body is not read for reporting. Manifest API answers are bounded at 1 MB.
- **Form bodies keep their field names.** A retry re-encodes
  `application/x-www-form-urlencoded` fields verbatim (dots, spaces, brackets)
  and repeats a repeated name, rather than through `parse_str`, which renames them.
- A `403` carrying `{"error":"project_disabled"}` or a `401` (rejected key)
  suppresses healing for five minutes; a server that times out, fails or
  cannot be reached is left alone for a minute. The deadline lives in a marker
  file in the temp directory, so it holds across php-fpm workers and requests.

## Data sent to Manifest

**Every call (metadata only).** For each call that is not healed, whatever its
status, the SDK sends its method, URL without the query string, userinfo or
fragment, status code, response time and time of the call. No headers and no
bodies. Calls are written to a small spool file in the temp directory and sent
in batches at the end of a web request, at most once per second per server;
recording one never slows the call. See CONTRACT.md, "Tracked requests", for
long-running workers and mod_php.

**Healable failures (full capture).** The failing request's URL, headers and body travel, plus the raw error
response. Credential **values** never do:

- query parameters with credential names are masked to `REDACTED`
- credential-carrying headers are masked, their names kept
- `user:password@host` is stripped from the URL
- credential-named top-level body keys are withheld, and restored locally on
  the retry

Query order, repeated parameters, dotted names, and raw encoding are preserved.
Masked query values are restored from the original request before a retry;
credential parameters omitted from the healed URL are reattached. A mask with
no local value to restore prevents the retry. Headers are updated without
regard to case, and body framing is recalculated by the original client.

## Development

```sh
docker build -f Dockerfile.test -t manifest-php-test .
docker run --rm -v "$PWD":/app manifest-php-test bash -c "composer install && composer test"
```

The test suite needs the `opentelemetry` extension, which is why it runs in
Docker. Hooks are process-global and cannot be removed, so every test that
installs one runs in its own process. CI runs PHP 8.2–8.5 and checks Guzzle 7
with Laravel, plus a separate Guzzle 8 job. Integration tests use local stub
servers and never require a live Manifest key.
