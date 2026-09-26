# Changelog

## [0.6.1](https://github.com/mnfst/manifest-php/compare/v0.6.0...v0.6.1) (2026-09-26)


### Bug Fixes

* close the critical and high review findings ([#48](https://github.com/mnfst/manifest-php/issues/48)) ([e318d35](https://github.com/mnfst/manifest-php/commit/e318d35f563c31426afc77ffd74dd2e4f8116791))

## [0.6.0](https://github.com/mnfst/manifest-php/compare/v0.5.0...v0.6.0) (2026-09-26)


### Features

* choose which calls reach Manifest with MNFST_ALLOWLIST / MNFST_DENYLIST ([#46](https://github.com/mnfst/manifest-php/issues/46)) ([4728657](https://github.com/mnfst/manifest-php/commit/47286571b6208d335c7c24f8fe5b2db084f9be6a))


### Bug Fixes

* doctor reads the key from the project's .env files ([#45](https://github.com/mnfst/manifest-php/issues/45)) ([1a238c7](https://github.com/mnfst/manifest-php/commit/1a238c7d92e7ec600a02b580171b72fd558c0a43))

## [0.5.0](https://github.com/mnfst/manifest-php/compare/v0.4.0...v0.5.0) (2026-09-26)


### ⚠ BREAKING CHANGES

* the extension hooks and prepend.php are removed. Guzzle clients need the middleware; raw curl calls are no longer seen.

### Features

* install without the opentelemetry extension ([#42](https://github.com/mnfst/manifest-php/issues/42)) ([495caa1](https://github.com/mnfst/manifest-php/commit/495caa1a5eb03586290705017997c7155536ea18))

## [0.4.0](https://github.com/mnfst/manifest-php/compare/v0.3.0...v0.4.0) (2026-09-24)


### Features

* track every call as metadata ([#35](https://github.com/mnfst/manifest-php/issues/35)) ([dc56778](https://github.com/mnfst/manifest-php/commit/dc56778857458a48b4bd26ad83cb74b730593880))
