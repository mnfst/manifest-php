# Manifest for PHP

**Turn 🔴 4xx API errors into 🟢 2xx in real time.**

## What is Manifest

Manifest is a self-healing layer that fixes and retries failed API requests on the fly.

* 🎯 **Fix failures automatically** before they impact your users.
* 🔔 **Get notified of root causes** so you can fix them permanently.
* 🔌 **Works across your stack** with internal APIs, external services, and agent tools.

## Prerequisites

- PHP 8.2 or higher
- The `opentelemetry` PECL extension:

```sh
pecl install opentelemetry && echo "extension=opentelemetry.so" >> "$(php -i | grep '^Loaded Configuration File' | cut -d' ' -f5)"
```

In Docker, one line:

```dockerfile
RUN pecl install opentelemetry && docker-php-ext-enable opentelemetry
```

## Get started

```sh
composer require mnfst/manifest-php
```

Manifest must load **before your application makes its first HTTP call**. A PHP
hook cannot attach to a function that has already been called, so an SDK that
loads late sees nothing. Point `auto_prepend_file` at the bundled entry point:

```ini
; php.ini, a .user.ini, or your php-fpm pool config
auto_prepend_file = /path/to/vendor/mnfst/manifest-php/prepend.php
```

Or call it yourself, as the first thing your application does:

```php
use function Mnfst\manifest;

manifest();  // before any HTTP call
```

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

It masks and validates the key, reports which coverage level is active, and
tells you whether the SDK is loading early enough.

## What is covered

| The app calls an API via… | Covered |
| --- | --- |
| Laravel's `Http` facade | ✅ healed |
| Guzzle, any client, including one built inside a third-party library | ✅ healed |
| CakePHP's `Cake\Http\Client` | ✅ healed |
| A library with its own raw `curl_*` client, such as `stripe/stripe-php` | ⚠️ **captured, never healed** |
| `file_get_contents` | ❌ not seen |

The `curl_*` row is a permanent limit of PHP, not a temporary gap. The extension
can observe an internal function but cannot replace its return value, so those
failures appear in your dashboard while your application still receives the
original error.

Laravel needs no configuration of its own: `Illuminate\Http\Client` runs on
Guzzle, so the Guzzle instrumentation covers it.

## Supported infrastructure

| Infrastructure | Supported |
| --- | --- |
| Docker, Kubernetes | ✅ one line in the Dockerfile |
| A VPS or your own server (Forge, Ploi) | ✅ `pecl install opentelemetry` |
| Heroku, Platform.sh | ⚠️ only if the platform ships the extension — unverified |
| Laravel Vapor, Bref | ⚠️ possible with a custom Lambda layer |
| Shared hosting (cPanel, Hostinger, OVH) | ❌ you do not control the runtime |

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

## More

[Configuration, limits & development](docs/guide.md) · [API contract](CONTRACT.md) · [Node.js SDK](https://www.npmjs.com/package/manifest) · [Python SDK](https://pypi.org/project/mnfst/) · [Website](https://manifest.build)
