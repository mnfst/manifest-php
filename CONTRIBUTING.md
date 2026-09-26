# Contributing to Manifest PHP SDK

Thanks for your interest in contributing to the Manifest PHP SDK!

## Prerequisites

- PHP 8.2+
- Composer 2

## Getting Started

1. Fork and clone the repository:

```bash
git clone https://github.com/<your-username>/manifest-php.git
cd manifest-php
composer install
```

## Development

The suite needs no PHP extension beyond `curl`:

```sh
composer install
composer test
```

Manifest's state is process-global, so every test that starts it runs in its
own process. Integration tests use local stub servers and never require a
live Manifest key.

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
- CakePHP's `Cake\Http\Client` (5.1+)
- Symfony's `HttpClient`, including scoped clients
- WordPress `wp_remote_*`
- Any Guzzle client that carries the middleware

Raw `curl_*` clients and Guzzle clients built inside a library that does not
let you pass your own are not seen. See
[the coverage details](docs/guide.md#supported-traffic).

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
