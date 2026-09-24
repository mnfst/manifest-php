# Contributing to Manifest PHP SDK

Thanks for your interest in contributing to the Manifest PHP SDK!

## Prerequisites

- PHP 8.2+
- Composer 2
- Docker, to run the test suite (it needs the `opentelemetry` extension)

## Getting Started

1. Fork and clone the repository:

```bash
git clone https://github.com/<your-username>/manifest-php.git
cd manifest-php
composer install
```

## Development

The test suite needs the `opentelemetry` extension, which is why it runs in
Docker:

```bash
docker build -f Dockerfile.test -t manifest-php-test .
docker run --rm -v "$PWD":/app manifest-php-test bash -c "composer install && composer test"
```

With the extension already installed locally, `composer test` runs PHPUnit
directly. Hooks are process-global and cannot be removed, so every test that
installs one runs in its own process. Integration tests use local stub servers
and never require a live Manifest key.

CI runs PHP 8.2 to 8.5 with Guzzle 7 and Laravel, plus a separate Guzzle 8 job,
and checks `composer validate --strict`.

## Making Changes

1. Create a branch from `main` for your change
2. Make your changes
3. Run tests to make sure everything passes
4. Write clear commit messages using conventional commits (e.g., `feat:`, `fix:`, `docs:`)
5. Open a pull request against `main`

## Commit Messages

Use conventional commit titles:
- `feat:` for new features (prepares a minor version)
- `fix:` for bug fixes (prepares a patch version)
- `docs:` for documentation changes
- `!` or `BREAKING CHANGE:` for breaking changes (prepares a major version)

GitHub keeps one rolling `chore: release …` pull request. Merging it sets
`Manifest::VERSION`, writes the changelog and tags the release; Packagist
publishes from the tag. Nothing is released before that pull request is merged.

## Supported Platforms

The SDK works with:
- Laravel's `Http` facade
- Guzzle, including clients built inside a third-party library
- CakePHP's `Cake\Http\Client`
- Symfony's `HttpClient`, curl and native transports
- WordPress `wp_remote_*` / `WpOrg\Requests`

Calls made with raw `curl_*` are captured but never healed: the extension can
observe an internal function but cannot replace its return value. See
[the coverage details](docs/guide.md#supported-traffic).

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
