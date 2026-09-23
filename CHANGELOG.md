# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0, minor versions may contain breaking changes; they are called out under **Changed** with an upgrade note.

## [Unreleased]

### Added

- Package skeleton for Laravel 12.61.1+ and 13.12+ on PHP 8.3+:
  - an auto-discovered service provider
  - a publishable config file (`dynamic-throttle-config` tag)
  - the `DYNAMIC_THROTTLE_ENABLED` kill switch
  - a "Dynamic Throttle" section in `php artisan about`
