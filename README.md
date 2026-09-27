<div align="center">

![Manifest SDK Architecture](./docs/github-sdk.png)

# Manifest for PHP

**The API resilience layer for your PHP apps.**

[![CI](https://github.com/mnfst/manifest-php/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/mnfst/manifest-php/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/mnfst/manifest-php?label=Packagist)](https://packagist.org/packages/mnfst/manifest-php)
[![Packagist downloads](https://img.shields.io/packagist/dm/mnfst/manifest-php?label=Packagist%20downloads)](https://packagist.org/packages/mnfst/manifest-php)

</div>

## What is Manifest

Manifest is the API resilience layer for your apps and agents. It works with every API they call: external services, your internal APIs and MCP tools.

* 🗺️ **See every API your app depends on**, and how reliable each one is.
* 🎯 **Repair failed API requests on the fly**, so your app keeps working.
* 🛠️ **Know what to fix in your code**, with a prompt for your coding agent.

## How it works

![How the SDK works: every call Manifest does not heal is recorded in the background as metadata (method, URL, status, timing, no body); a failure Manifest can heal is sent with its error, patched, and retried once, returning a 200 OK](./docs/sdk-flow-diagram.png)

Every call your app makes is reported to Manifest as metadata only (method, URL without its query string, status and timing), in batches at the end of a web request. A failure Manifest can heal is sent in full, so it can be repaired. [What is sent](docs/guide.md#data-sent-to-manifest).

## Prerequisites

- <a href="https://www.php.net/downloads" target="_blank">PHP 8.2</a> or higher
- The PHP `curl` extension, used to contact Manifest

No PHP extension to compile, nothing to change on the server.

## Get started

### Start with your agent

```
"Install Manifest in this app: https://dashboard.manifest.build/prompt-php.md"
```

[Read the prompt →](https://dashboard.manifest.build/prompt-php.md)

### Start with code

```sh
composer require mnfst/manifest-php
```

Then connect it to your HTTP client. Console commands (`artisan`, `bin/cake`,
`bin/console`, WP-CLI) are covered the same way as web requests.

**Laravel** — nothing to write. The service provider is discovered automatically
and covers every `Http::` call. The key is read from `MNFST_KEY`, or from
`config/services.php`:

```php
'manifest' => ['key' => env('MNFST_KEY'), 'url' => env('MNFST_URL')],
```

**CakePHP 5.1+** — in `src/Application.php`:

```php
public function bootstrap(): void
{
    parent::bootstrap();
    $this->addPlugin(\Mnfst\Cake\ManifestPlugin::class);
}
```

**Symfony** — in `config/bundles.php`:

```php
Mnfst\Symfony\ManifestBundle::class => ['all' => true],
```

**WordPress** — create `wp-content/mu-plugins/manifest.php`:

```php
<?php
require_once ABSPATH . 'vendor/autoload.php';   // wherever Composer installed it
\Mnfst\WordPress\listen();
\Mnfst\manifest();
```

**Any other Guzzle client** — push the middleware on its handler stack, and start the SDK once:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use function Mnfst\manifest;

manifest();

$stack = HandlerStack::create();
$stack->push(\Mnfst\Guzzle\middleware());
$client = new Client(['handler' => $stack]);
```

A library that accepts a Guzzle or PSR-18 client (the AWS SDK, most API
clients) is covered when you pass it that client.

The SDK stays silent under PHPUnit and Pest, so `Http::fake()` answers are
never reported as failures; `MNFST_IN_TESTS=1` opts back in. See
[the guide](docs/guide.md#testing).

### Upgrading from 0.4

1. Remove the `auto_prepend_file` line and any `manifest()` call you added for loading order.
2. Add the line for your framework above (Laravel: nothing).
3. You can remove the `opentelemetry` extension if nothing else on the server uses it.

## Setup

1. Create a project in your [Manifest dashboard](https://dashboard.manifest.build) and copy its project key.
2. Set the key as an environment variable, or in the project's `.env` file:

```sh
export MNFST_KEY='your-project-key'
```

Verify the install from your project directory:

```sh
vendor/bin/manifest doctor
```

It reads the key from the shell, then from the project's `.env.local`, `.env` or
`config/.env`. It masks and validates the key, and checks that each framework
the project uses has its adapter in place.

## Try it

Send a request that fails with a 4xx error, such as a value the API rejects:

```php
use Illuminate\Support\Facades\Http;

$response = Http::post('https://api.example.com/orders', [
    'limit' => 500,   // rejected by the API
]);
```

The failed request appears in your [Manifest dashboard](https://dashboard.manifest.build), grouped with others like it in an issue. Once Manifest has a patch for that error, the next request that fails the same way is repaired and retried, and your app receives the answer to the retry.

## Choosing which calls reach Manifest

Keep calls out of Manifest entirely: they are neither repaired nor tracked, and nothing about them leaves your app. Each entry is a domain or a domain with a path:

```sh
MNFST_ALLOWLIST=stripe.com                       # only Stripe
MNFST_ALLOWLIST=stripe.com/v1/payment_intents    # only this Stripe endpoint
MNFST_DENYLIST=stripe.com/v1/charges,internal.example.com   # never these
```

- A domain covers its subdomains, with or without a path: `stripe.com` and `stripe.com/v1/charges` both match `api.stripe.com`.
- A path matches whole segments: `/v1/charges` covers `/v1/charges/ch_123`, not `/v1/charges_export`. Paths are case-sensitive.
- A scheme, port, query or fragment in an entry is ignored. `*` in a path is not supported yet: the entry is skipped with a warning, and an allowlist made only of skipped entries lets nothing through.
- The denylist wins over the allowlist. With no allowlist, every call is eligible.
- Paths are compared decoded, with `.` and `..` resolved, so `/%70rivate` and `/public/..%2Fprivate` both match `/private`. A patched retry is filtered too: a repair never moves a call onto an excluded path.

Or in code: `\Mnfst\manifest(denylist: ['stripe.com/v1/charges']);`. An option overrides its environment variable.

## What is covered

| The app calls an API via… | Covered |
| --- | --- |
| Laravel's `Http` facade | ✅ healed |
| CakePHP's `Cake\Http\Client` (5.1+) | ✅ healed |
| Symfony's `HttpClient`, including scoped clients | ✅ healed |
| WordPress `wp_remote_*` | ✅ healed |
| A Guzzle client that carries the middleware | ✅ healed |
| A Guzzle client built inside a library that does not accept yours | ❌ not seen |
| Raw `curl_*`, such as `stripe/stripe-php` | ❌ not seen |
| `file_get_contents` | ❌ not seen |

Symfony responses consumed through `stream()` or with `buffer => false` stay
under the caller's control and are not healed. See [the coverage details](docs/guide.md#supported-traffic).

## Supported infrastructure

| Infrastructure | Supported |
| --- | --- |
| Docker, Kubernetes | ✅ |
| A VPS or your own server (Forge, Ploi) | ✅ |
| Heroku, Platform.sh | ✅ |
| Laravel Vapor, Bref | ✅ |
| Shared hosting (cPanel, Hostinger, OVH) | ✅ where Composer runs |

## What leaves the machine

Masking is done in this process, before anything is sent, by [mnfst/http-redact](https://github.com/mnfst/http-redact#threat-model): its README lists exactly what is masked and what is not.

| Call | Sent to Manifest | Never sent |
| --- | --- | --- |
| A call Manifest does not heal, whatever its status | method, scheme, host, port, path (a secret in it, like a webhook token, replaced by `REDACTED`), status, timing | query string, headers, bodies |
| A failure Manifest can heal (a 4xx other than 401, 402, 403 and 429) | URL, headers, request body and error response, with every credential value replaced by `REDACTED` wherever it sits: query, path, headers, cookies, nested body fields, vendor keys and tokens in free text, the error response | the masked values, and `user:password@` |
| The retry | nothing: it goes to the original API, through your own client, with the real values | — |
| A call excluded by [`MNFST_ALLOWLIST` / `MNFST_DENYLIST`](#choosing-which-calls-reach-manifest) | nothing | everything |

One known limit: a secret inside a URL path (a webhook URL, `/bot<token>/`) is
sent as is. The rules are in [`src/Wire.php`](src/Wire.php) and [CONTRACT.md](CONTRACT.md).

## More

[Configuration, limits & development](docs/guide.md) · [API contract](CONTRACT.md) · [Website](https://manifest.build)
