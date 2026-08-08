# Repository Guidelines

## Project Structure & Module Organization

LoopDeck is a PHP 8.1+ ThinkPHP 8 application. Code lives in `app/`: `index/` serves users, `admin/` provides administration, `cron/` runs scheduled work, and `service/`, `middleware/`, and `command/` hold shared behavior. Platform adapters are under `extend/`. Configuration belongs in `config/`; templates sit in each app's `view/`; browser assets and the front controller are in `public/`. Container scripts live in `docker/`, and regression checks in `tests/`.

Do not edit generated or local-state directories such as `vendor/` and `runtime/`. Treat `public/static/uploads/` as runtime data.

## Build, Test, and Development Commands

- `composer install` installs locked PHP dependencies and refreshes autoloading.
- `php think run` starts the ThinkPHP development server (after configuring the database).
- `php tests/AutomaticScheduleTest.php` runs one offline regression test.
- `for test_file in tests/*Test.php; do php "$test_file"; done` runs the same offline suite used by the Docker build.
- `docker compose build` validates dependencies, runs tests, and builds the image.
- `docker compose up --wait` starts the app, scheduler, updater, and MySQL services; the default app port is `8001`.

Copy `.env.example` to `.env` for containers; use `config/Db.example.php` for local database configuration.

## Coding Style & Naming Conventions

Follow the existing PSR-12-style PHP: four-space indentation, braces on new lines, one class per file, and `declare(strict_types=1);` in new files. Match the `app\` PSR-4 namespace to the directory tree. Use PascalCase classes, camelCase methods/properties, and UPPER_SNAKE_CASE constants. Prefer typed signatures and `final` where extension is not intended. No repository-wide formatter is configured; follow adjacent code.

## Testing Guidelines

Tests are executable PHP scripts, not PHPUnit cases. Name regressions `FeatureNameTest.php`, load `vendor/autoload.php`, throw on failed assertions, and print a success line. Cover every bug fix and important branch; no percentage threshold is enforced. `LiveSmoke.php` scripts contact upstream services and run only when invoked explicitly.

## Commit & Pull Request Guidelines

History uses concise Conventional Commit subjects: `feat: add ...`, `fix: prevent ...`, `test: align ...`, and `docs: clarify ...`. Keep commits focused. Pull requests should explain behavior and risk, link issues, list commands run, and include screenshots for UI changes. Highlight schema, environment, Docker, or scheduler changes.

## Security & Configuration

Never commit `.env`, `config/Db.php`, credentials, tokens, logs, or generated uploads. Keep secret examples inert, and review dependency changes with `composer audit --locked`.
