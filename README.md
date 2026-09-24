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

Every call your app makes is reported to Manifest as metadata only (method, URL without its query string, status and timing), in batches at the end of a web request. A failure Manifest can repair is sent in full, so it can be repaired. [What is sent](docs/guide.md#data-sent-to-manifest).

## Prerequisites

- <a href="https://www.php.net/downloads" target="_blank">PHP 8.2</a> or higher
- The PHP `curl` extension, used to contact Manifest
- The <a href="https://pecl.php.net/package/opentelemetry" target="_blank">opentelemetry</a> extension. PHP cannot watch an HTTP client without it, so the SDK sees nothing until it is installed.

On a server you manage:

```sh
pecl install opentelemetry
```

Then add `extension=opentelemetry` to your `php.ini`.

In Docker, one line in your Dockerfile:

```dockerfile
RUN pecl install opentelemetry && docker-php-ext-enable opentelemetry
```

## Get started

### Start with your agent

```
"Install Manifest in this app: https://dashboard.manifest.build/prompt-php.md"
```

[Read the prompt →](https://dashboard.manifest.build/prompt-php.md)

The prompt installs the extension, wires the loading order, and stops to let you paste your key.

### Start with code

1. Create a project in your [Manifest dashboard](https://dashboard.manifest.build) and copy its project key.

2. Install the SDK:

   ```sh
   composer require mnfst/manifest-php
   ```

3. Load Manifest **before your application makes its first HTTP call**. A PHP hook cannot attach to a function that has already run, so an SDK that loads late sees nothing. Point `auto_prepend_file` at the bundled entry point:

   ```ini
   ; php.ini, a .user.ini, or your php-fpm pool config
   auto_prepend_file = vendor/mnfst/manifest-php/prepend.php
   ```

   Or call it yourself, as the first thing your application does:

   ```php
   use function Mnfst\manifest;

   manifest();  // before any HTTP call
   ```

4. Set your key in the environment of your server:

   ```sh
   export MNFST_KEY='your-project-key'
   ```

5. Restart php-fpm, then check the install from your project directory:

   ```sh
   vendor/bin/manifest doctor
   ```

   It masks and validates the key, reports which coverage level is active, and tells you whether the SDK loads early enough.

### Laravel

`auto_prepend_file` runs before Laravel reads your `.env` file, so a key stored in `.env` is not seen there. Make the call from a service provider instead:

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    \Mnfst\manifest(config('services.manifest.key'), config('services.manifest.url'));
}
```

with `'manifest' => ['key' => env('MNFST_KEY'), 'url' => env('MNFST_URL')]` in `config/services.php`. The Laravel `Http` facade and the CakePHP client are covered with no other code. See [the Laravel guide](docs/guide.md#laravel).

The SDK stays silent under PHPUnit and Pest, so `Http::fake()` answers are never reported as failures. `MNFST_IN_TESTS=1` opts back in. See [the guide](docs/guide.md#testing).

## Try it

Send a request that fails with a 4xx error, such as a value the API rejects:

```php
use Illuminate\Support\Facades\Http;

$response = Http::post('https://api.example.com/orders', [
    'limit' => 500,   // rejected by the API
]);
```

The failed request appears in your [Manifest dashboard](https://dashboard.manifest.build), grouped with others like it in an issue. Once Manifest has a patch for that error, the next request that fails the same way is repaired and retried, and your app receives the answer to the retry.

## What is covered

| The app calls an API via… | Covered |
| --- | --- |
| Laravel's `Http` facade | ✅ healed |
| Guzzle, any client, including one built inside a third-party library | ✅ healed |
| CakePHP's `Cake\Http\Client` | ✅ healed |
| Symfony's `HttpClient` (curl & native transports) | ✅ healed |
| WordPress `wp_remote_*` / `WpOrg\Requests` | ✅ healed |
| A library with its own raw `curl_*` client, such as `stripe/stripe-php` | ⚠️ **captured, never healed** |
| `file_get_contents` | ❌ not seen |

The `curl_*` row is a permanent limit of PHP, not a temporary gap. The extension can observe an internal function but cannot replace its return value, so those failures appear in your dashboard while your application still receives the original error.

Symfony responses consumed through `stream()` or with `buffer => false` stay under the caller's control and are not healed. See [the coverage details](docs/guide.md#supported-traffic).

A long-running worker, such as Laravel Octane, RoadRunner or `queue:work`, sends its requests to Manifest only when it restarts. See [limits and failure behaviour](docs/guide.md#limits-and-failure-behaviour).

## Supported infrastructure

| Infrastructure | Supported |
| --- | --- |
| Docker, Kubernetes | ✅ one line in the Dockerfile |
| A VPS or your own server (Forge, Ploi) | ✅ `pecl install opentelemetry` |
| Heroku, Platform.sh | ⚠️ only if the platform ships the extension |
| Laravel Vapor, Bref | ⚠️ possible with a custom Lambda layer |
| Shared hosting (cPanel, Hostinger, OVH) | ❌ you do not control the runtime |

## More

[Documentation](https://docs.manifest.build) · [Configuration, limits & development](docs/guide.md) · [API contract](CONTRACT.md) · [Website](https://manifest.build)
