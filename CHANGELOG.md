# Changelog

All notable changes to **Excubia App** (`qiick-io/excubia-app`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- CI on pull requests and pushes to `develop` and `main` (PHP 8.4 and 8.5), Dependabot, and an end-to-end contract test against `qiick/excubia-core` (`scripts/e2e/core-contract.sh`) (#112).
- Axe accessibility smoke test for the main pages (`tests/Browser`, Pest browser plugin with Playwright) and an `Accessibility` CI job that fails on serious or critical violations (#112).
