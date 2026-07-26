# Upgrading to Alchemy 5

Alchemy 5 grows from a test/lint setup helper into a full QA pipeline: tests (Pest/PHPUnit), code style (PHP CS Fixer), automated refactoring (Rector), static analysis (PHPStan), and CI generation for GitHub Actions, GitLab CI and CircleCI — all from the same `alchemy.yml`.

**Your existing setup keeps working.** The old commands (`alchemy setup --test`, `--lint`, `--actions`) still exist as aliases, so projects with v4-era composer scripts run unchanged. Everything below is what you get when you opt in.

## TL;DR for existing projects

```bash
composer require leafs/alchemy:^5.0 --dev
./vendor/bin/alchemy init --force   # refreshes composer scripts + imports any config you have
```

Then use the new commands: `composer run test`, `lint`, `fmt`, `refactor`, `analyse`, `ci`.

## Behavior changes to know about

### 1. Exit codes are real now

Alchemy 4 always exited `0`, even when your tests failed — CI built on it could never go red. Alchemy 5 propagates real exit codes. If your pipeline suddenly starts failing after upgrading, **that's the fix working**: it was failing before too, silently.

### 2. Lint checks by default, `fmt` fixes

- `alchemy lint` (and the new `composer run lint` script) **checks** style and fails on violations — nothing is rewritten. This is what generated CI uses, so style violations now actually fail CI.
- `alchemy fmt` is the command that fixes your code (what `lint` used to do).
- Want CI to auto-commit style fixes instead of failing (the v4 behavior)? Set `lint.autofix: true` — GitHub only.
- Risky fixes are still applied by default; turn them off with `lint.risky: false`.

### 3. The `event:` config key is now `events:`

v4's stub wrote `event:` but the code read `events`, so custom CI triggers were silently ignored and every workflow ran on `push` only. If your `alchemy.yml` has `event:`, rename it to `events:` — both are accepted, but `events:` is the documented key, and your configured triggers now actually apply.

### 4. PHPUnit parallel uses paratest

`tests.parallel: true` with the phpunit engine used to pass a `--parallel` flag PHPUnit doesn't have. Alchemy 5 installs and runs paratest instead. (Pest keeps using its built-in parallel mode.)

### 5. Your `phpunit.xml` is safe

v4 could overwrite and delete a hand-written `phpunit.xml`. Alchemy 5 parks your file during a run and restores it after — and `alchemy init` can import it into `alchemy.yml` when you're ready to switch.

### 6. `config:eject` is now `eject` — and it works

The old eject command targeted a config format that no longer existed. `alchemy eject` now exports a real `phpunit.xml` + `.php-cs-fixer.dist.php` and rewires your composer scripts to call the engines directly.

## New in Alchemy 5

- **`alchemy init`** — detects your framework (Leaf, Laravel, Symfony, Slim, plain PHP) and imports existing phpunit.xml / php-cs-fixer configs into `alchemy.yml`
- **Rector**: add a `refactor:` section, run `composer run refactor` (`-- --check` in CI)
- **PHPStan**: add an `analyse:` section, run `composer run analyse`
- **CI providers**: `actions.provider: github | gitlab | circleci` (or a list) — same yml, any pipeline
- **`alchemy switch <target>`** — move between CI providers or test engines with one command
- **Near-1:1 phpunit.xml mapping**: named suites, per-suite patterns/excludes, `env`/`ini`/`server` values, coverage excludes, and any root phpunit attribute passed through via `tests.config`
- **Windows-friendly scripts**: composer scripts are written as `@php vendor/bin/alchemy ...` so they run on every platform
- Everything still installs lazily — no engine is added to your project until the command that needs it first runs

## Config keys that moved or matter

| v4 | v5 |
| --- | --- |
| `actions.event` | `actions.events` (old key still read) |
| `tests.config.xmlnxsi` | `tests.config['xmlns:xsi']` (old key still read) |
| — | `tests.suites`, `tests.env/ini/const/server`, `tests.coverage.exclude`, `tests.extensions` |
| — | `lint.risky`, `lint.exclude`, `lint.autofix` |
| — | `refactor.*`, `analyse.*`, `actions.provider` |
