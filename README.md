<div align="center">

![Manifest SDK Architecture](./docs/github-sdk.png)

# Manifest for PHP

**Turn 🔴 4xx API errors into 🟢 2xx in real time.**

[![CI](https://github.com/mnfst/manifest-php/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/mnfst/manifest-php/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/mnfst/manifest-php?label=Packagist)](https://packagist.org/packages/mnfst/manifest-php)
[![Packagist downloads](https://img.shields.io/packagist/dm/mnfst/manifest-php?label=Packagist%20downloads)](https://packagist.org/packages/mnfst/manifest-php)

</div>

## What is Manifest

Manifest is a self-healing layer that fixes and retries failed API requests on the fly.

* 🎯 **Fix failures automatically** before they impact your users.
* 🔔 **Get notified of root causes** so you can fix them permanently.
* 🔌 **Works across your stack** with internal APIs, external services, and agent tools.

## How it works

![How Manifest heals a failed request: a 400 reaches Manifest, drops to a patch from the knowledge base or the healing agents, and is retried once, returning a 200 OK](./docs/sdk-flow-diagram.png)

## Prerequisites

- <a href="https://www.php.net/downloads" target="_blank">PHP 8.2</a> or higher
- The <a href="https://pecl.php.net/package/opentelemetry" target="_blank">opentelemetry</a> extension:

```sh
pecl install opentelemetry && docker-php-ext-enable opentelemetry
```

In Docker, one line:

```dockerfile
RUN pecl install opentelemetry && docker-php-ext-enable opentelemetry
```

PHP cannot instrument an HTTP client without it, so the SDK sees nothing until it is installed.

## Get started

### Start with your agent

```
"Install Manifest in this app: https://app-staging.manifest.build/prompt-php.md"
```

[Read the prompt →](https://app-staging.manifest.build/prompt-php.md)

The prompt installs the extension, wires the loading order, and stops to let you paste your key.

### Start with code

```sh
composer require mnfst/manifest-php
```

Manifest must load **before your application makes its first HTTP call**. A PHP hook cannot attach
to a function that has already run, so an SDK that loads late sees nothing. Point
`auto_prepend_file` at the bundled entry point:

```ini
; php.ini, a .user.ini, or your php-fpm pool config
auto_prepend_file = vendor/mnfst/manifest-php/prepend.php
```

Or call it yourself, as the first thing your application does:

```php
use function Mnfst\manifest;

manifest();  // before any HTTP call
```

Laravel and CakePHP need no code of their own for coverage: their HTTP clients
are instrumented. In Laravel, make the call from a service provider and keep it
out of the test suite, where `Http::fake()` answers would be reported as real
failures:

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    if ($this->app->runningUnitTests()) {
        return;
    }
    manifest(config('services.manifest.key'), config('services.manifest.url'));
}
```

with `'manifest' => ['key' => env('MNFST_KEY'), 'url' => env('MNFST_URL')]` in
`config/services.php`. [The guide](docs/guide.md#laravel) explains why a
`phpunit.xml` override alone does not keep it quiet under `php artisan test`.

## Setup

1. Create a project in your [Manifest dashboard](https://dashboard.manifest.build) and copy its project key.
2. Set the key as an environment variable:

```sh
export MNFST_KEY='your-project-key'
```

Verify the install from your project directory:

```sh
vendor/bin/manifest doctor
```

It masks and validates the key, reports which coverage level is active, and tells you whether the
SDK is loading early enough.

## Try it

Send a request that would normally fail. Manifest catches it, repairs it, and retries:

```php
use Illuminate\Support\Facades\Http;

$response = Http::post('https://api.example.com/orders', [
    'limit' => 500,   // Invalid? Manifest fixes it and retries.
]);

echo $response->status();  // See the 200 OK response.
```

Check your [Manifest dashboard](https://dashboard.manifest.build) to see all repairs and insights.

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

The `curl_*` row is a permanent limit of PHP, not a temporary gap. The extension can observe an
internal function but cannot replace its return value, so those failures appear in your dashboard
while your application still receives the original error.

## Supported infrastructure

| Infrastructure | Supported |
| --- | --- |
| Docker, Kubernetes | ✅ one line in the Dockerfile |
| A VPS or your own server (Forge, Ploi) | ✅ `pecl install opentelemetry` |
| Heroku, Platform.sh | ⚠️ only if the platform ships the extension |
| Laravel Vapor, Bref | ⚠️ possible with a custom Lambda layer |
| Shared hosting (cPanel, Hostinger, OVH) | ❌ you do not control the runtime |

## More

[Configuration, limits & development](docs/guide.md) · [API contract](CONTRACT.md) · [Website](https://manifest.build)
