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
or whitespace-only key disables capture, including adapters already in place.
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

`manifest()` is idempotent. A later call only refreshes the key, URL and
callback the adapters use, and never warns. A framework that boots the
application several times per process (a test runner, Octane) is fine.

## Testing

The SDK detects a PHPUnit or Pest run and stays off, so a suite that
fakes its HTTP (`Http::fake()`, Guzzle's `MockHandler`, Symfony's
`MockHttpClient`) never reports those faked 4xx to Manifest. Nothing to
configure. Set `MNFST_IN_TESTS=1` when you do want healing during tests, e.g.
integration tests against a staging server. Capture also checks the runner at
request time, so an SDK started before a custom test bootstrap becomes silent
once the runner starts.

## Verifying the installation

```sh
vendor/bin/manifest doctor
```

It prints the SDK version, the masked key, whether each framework the project
uses has its adapter in place, and whether the server accepts the key. It
exits non-zero when installation checks fail. The probe does not record an
installation.

## Laravel

The service provider is discovered by Composer, so there is nothing to write.
It adds the Guzzle middleware to every `Http::` call through
`Http::globalMiddleware()` and starts the SDK with
`config('services.manifest.key')` and `config('services.manifest.url')`, or
`MNFST_KEY` and `MNFST_URL`. Config survives `config:cache`. A healed call fires
one `ResponseReceived` event, with the healed response. If you turned package
discovery off, add `Mnfst\Laravel\ManifestServiceProvider` to `bootstrap/providers.php`.

The SDK stays off under a PHPUnit or Pest run, so `Http::fake()` answers
are never reported as real failures; you do not need to guard the call
yourself. Set `MNFST_IN_TESTS=1` to opt back in for integration tests that hit
a real server. (A `phpunit.xml` `<env name="MNFST_KEY" value=""/>` would not
have worked under `php artisan test` anyway: the artisan process hands its
`$_SERVER`, `.env` values included, to PHPUnit as the real environment, and
`<env>` never touches `$_SERVER`, which Laravel's `env()` reads first.)

`Http::retry()` retries a failed call; each attempt that fails is a capture of
its own, so a failure Manifest cannot fix is reported once per attempt.

## CakePHP

`$this->addPlugin(\Mnfst\Cake\ManifestPlugin::class);` in `Application::bootstrap()`.
The plugin listens to `HttpClient.afterSend` on the global event manager, which
every `Cake\Http\Client` dispatches to, and replaces the result with the healed
response. It reads `Configure::read('Manifest.key')` and `Manifest.url`, then
`MNFST_KEY`. The event exists from CakePHP 5.1; on older versions the plugin
does nothing and `manifest doctor` says so. To wire it without the plugin, call
`\Mnfst\Cake\listen();` and `\Mnfst\manifest();` in `config/bootstrap.php`.

## Symfony

`Mnfst\Symfony\ManifestBundle::class => ['all' => true]` in `config/bundles.php`.
The bundle decorates the HTTP transport that `http_client` and every scoped
client are built on, so all of them are covered. Outside the container, wrap a
client yourself: `new \Mnfst\Symfony\HealingHttpClient(HttpClient::create())`, and
call `\Mnfst\manifest()` once. Headers set only as default options of the
transport (`framework.http_client.default_options`) are not in the captured
request; the retry still sends them.

## WordPress

A must-use plugin loads before every other plugin and on WP-CLI:

```php
<?php // wp-content/mu-plugins/manifest.php
require_once ABSPATH . 'vendor/autoload.php';   // wherever Composer installed it
\Mnfst\WordPress\listen();
\Mnfst\manifest();
```

It uses the `pre_http_request` and `http_response` filters, so every
`wp_remote_*` call is covered.

## Guzzle

Push `\Mnfst\Guzzle\middleware()` on the client's handler stack and call
`\Mnfst\manifest()` once. The retry goes through the rest of the stack, so your
other middleware, a `MockHandler` or a proxy setting apply to it too. A client
with the middleware twice (Laravel's global middleware plus your own) heals
each call once.

## Supported traffic

Laravel's `Http` facade, `Cake\Http\Client` (5.1+), Symfony's `HttpClient`,
WordPress's `wp_remote_*`, and any Guzzle client that carries the middleware
are healed. Raw `curl_*` clients, `file_get_contents`, and Guzzle clients built
inside a library that does not let you pass your own are not seen.

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
composer install
composer test
```

The suite needs no extension. Manifest's state is process-global, so every
test that starts it runs in its own process. CI runs PHP 8.2–8.5 and checks
Guzzle 7 with Laravel, plus a separate Guzzle 8 job. Integration tests use
local stub servers and never require a live Manifest key.
