# Contributing to ZeroBoiler Analytics

Thank you for your interest in contributing! This guide covers the essentials.

## Development Setup

```bash
git clone https://github.com/zeroboiler/analytics.git
cd analytics
cp .env.example .env
composer install
```

## Code Standards

- **PHP 8.5+** — All source files use `declare(strict_types=1)`.
- **Return types** — Every method must have an explicit return type declaration.
- **Docblocks** — All public/protected methods must have PHPDoc with `@param`, `@return`, and `@since` tags.
- **Final classes** — All concrete classes are `final`. Only abstract classes and interfaces are non-final.
- **MIT header** — Every PHP file must start with the license header comment.
- **Formatting** — Follow PSR-12. We use [Laravel Pint](https://github.com/laravel/pint).
- **Static analysis** — PHPStan level 9 with `.neon.dist` configuration.
- **EditorConfig** — Install the EditorConfig plugin for your editor to match project settings.

## Running Checks

```bash
# Code formatting (must pass)
vendor/bin/pint --test

# Static analysis (must pass)
vendor/bin/phpstan analyse --no-progress

# Tests (minimum 43% coverage)
vendor/bin/pest --coverage --min=43

# All quality checks at once
vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && vendor/bin/pest
```

## Pull Request Process

1. Create a feature branch from `main`.
2. Write code following the standards above.
3. Add tests for new functionality.
4. Ensure all CI checks pass.
5. Update the changelog (if user-facing).
6. Submit the PR with a clear description.

## Versioning

This project uses [Semantic Versioning](https://semver.org/). When updating the version:

1. Update `composer.json` `version` field
2. Update `package.json` `version` field  
3. Update `AnalyticsEvent::VERSION` constant in `src/DTO/AnalyticsEvent.php`
4. Update `@version` in `resources/js/analytics.js` (header + `getVersion()`)
5. Update `@version` in `resources/js/analytics.d.ts`
6. Update `@version` in `resources/js/analytics.constants.js`
7. Update `@version` in all Svelte composables (`resources/js/use*.svelte.js`)
8. Update `@version` in `AnalyticsServiceProvider.php`
9. Update `EXPECTED_VERSION` in `AnalyticsIntegrityCommand.php`
10. Update README badge
11. Add changelog entry

## Architecture

- **Trackers** (`src/Trackers/`) — Provider-specific implementations of `TrackerInterface`
- **Events** (`src/Events/`) — Typed event catalogs (Ecommerce, SaaS, Engagement, etc.)
- **Services** (`src/Services/`) — Business logic services
- **DTO** (`src/DTO/`) — Data transfer objects
- **Commands** (`src/Console/Commands/`) — Artisan commands
- **Middleware** (`src/Http/Middleware/`) — HTTP middleware
- **JS Client** (`resources/js/`) — Browser/Node analytics library

## Questions?

Open a [GitHub Discussion](https://github.com/zeroboiler/analytics/discussions) for questions about contributing.
