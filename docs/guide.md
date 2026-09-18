# SDK guide

## Configuration

`manifest()` takes three optional arguments, all of which fall back to the
environment.

| Argument | Environment | Default |
| --- | --- | --- |
| `$apiKey` | `MNFST_KEY` | none — without it nothing is sent |
| `$url` | `MNFST_URL` | `https://api.manifest.build` |
| `$onHeal` | — | none |

Everything that is policy — whether a given app, endpoint or direction gets
healed — lives server-side, where it is editable without a deploy.

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
loads early enough, and the project name the key resolves to. It exits non-zero
when any of those fail.

## Supported traffic

Guzzle (every client in the process, whoever constructed it), Laravel's `Http`
facade, and `Cake\Http\Client` are instrumented and healed. A library with its
own raw `curl_*` client is captured but never healed. `file_get_contents` and
other stream-based HTTP are not covered.

## Limits and failure behaviour

- **Heal timeout: 10 seconds, not configurable.** A PHP heal runs inside a web
  request, and a web server commonly cuts the request off at 30 seconds. A
  longer heal would turn a fixable 400 into a 504.
- **One retry per captured failure.** The retry's response, including another
  failure, is returned to the caller.
- **Fail open.** Any error inside the SDK returns the caller's original
  response. A hook that throws degrades to a PHP warning.
- **Request bodies over 256 KB are not parsed**, and response bodies travel
  capped at 64 KB.
- A `403` carrying `{"error":"project_disabled"}` suppresses healing for five
  minutes.

## Data sent to Manifest

The failing request's URL, headers and body travel, plus the raw error
response. Credential **values** never do:

- query parameters with credential names are masked to `REDACTED`
- credential-carrying headers are masked, their names kept
- `user:password@host` is stripped from the URL
- credential-named top-level body keys are withheld, and restored locally on
  the retry

## Development

```sh
docker build -f Dockerfile.test -t manifest-php-test .
docker run --rm -v "$PWD":/app manifest-php-test bash -c "composer install && composer test"
```

The test suite needs the `opentelemetry` extension, which is why it runs in
Docker. Hooks are process-global and cannot be removed, so every test that
installs one runs in its own process.
