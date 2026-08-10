<!-- markdownlint-disable no-inline-html -->
<p align="center">
    <br><br>
    <img src="https://github.com/user-attachments/assets/3a50d848-4290-4a46-8ab1-bc0a148da375" height="100"/>
</p>

<h1 align="center">Alchemy</h1>

[![Latest Stable Version](https://poser.pugx.org/leafs/alchemy/v/stable)](https://packagist.org/packages/leafs/alchemy)
[![Total Downloads](https://poser.pugx.org/leafs/alchemy/downloads)](https://packagist.org/packages/leafs/alchemy)
[![License](https://poser.pugx.org/leafs/alchemy/license)](https://packagist.org/packages/leafs/alchemy)

Alchemy is an integrated testing/style fixing tool for your PHP applications. Alchemy handles your test/linting setup and any other integration you might need to run your tests like CI/CD. Alchemy is not a testing framework or style fixer, it's a tool that manages all the nasty setup for you.

## 📦 Setting Up

You can install alchemy with leaf CLI

```bash
leaf install alchemy
```

Or with composer

```bash
composer require leafs/alchemy
```

Once installed, Alchemy will automatically set up an `alchemy.yml` file in your project's root which you can use to configure your tests, linting and github actions.

## 🗂 Your Alchemy File

The `alchemy.yml` file should look something like this:

```yaml
app:
  - app
  - src

tests:
  engine: pest
  parallel: true
  paths:
    - tests
  files:
    - '*.test.php'
  coverage:
    local: false # coverage on demand locally...
    actions: true # ...always on CI

lint:
  preset: 'PSR12'
  ignore_dot_files: true
  rules:
    array_syntax:
      syntax: 'short'
    no_unused_imports: true
    single_quote: true
    ordered_imports:
      imports_order: null
      case_sensitive: false
      sort_algorithm: 'alpha'

analyse:
  level: 5

refactor:
  php: true # upgrade sets for the PHP version in your composer.json
  sets:
    - dead-code
    - code-quality

actions:
  run: # CI defaults to the universal jobs — add analyse/refactor to gate merges on them too
    - 'lint'
    - 'tests'
  php:
    extensions: json, zip
    versions:
      - '8.3'
  events:
    - 'push'
    - 'pull_request'
```

`alchemy init` writes all of these sections — a section's presence is what opts a tool in, so the full pipeline is on by default and deleting a section is how you say no.

You can make edits to this file to suit your needs. The `app` key is an array of directories to look for your app files in. The `tests` key is an array of configurations for your tests. The `lint` key is an array of configurations for your code styling checks.

If you need more control, the `tests` key maps (almost) 1:1 to phpunit.xml — named suites, per-suite file patterns and excludes, `<php>` block values, and any root phpunit attribute passed through verbatim via `config`:

```yaml
tests:
  engine: pest
  suites:
    Unit:
      paths:
        - tests/unit
      exclude:
        - tests/unit/legacy
    Feature:
      paths:
        - tests/feature
      files:
        - '*Test.php'
  config: # any phpunit.xml attribute, passed through as-is
    stopOnFailure: true
    executionOrder: random
  env:
    APP_ENV: testing
  server:
    MY_FLAG: 'on'
  ini:
    memory_limit: 512M
  coverage:
    exclude:
      - src/legacy
  flags: # standing engine flags — any pest/phpunit option (pest 5: tia, shard=1/4, ...)
    - tia

lint:
  preset: PSR12
  risky: false # risky fixes are on by default
  exclude:
    - legacy
```

The linter is swappable too. Laravel projects usually already lint with [Pint](https://laravel.com/docs/pint), so `lint` takes a `provider` key (`phpcsfixer` is the default) — and since Pint's rules *are* php-cs-fixer rules, your `rules` and `exclude` entries carry over unchanged, with Pint-only keys (`notPath`, `notName`) passing through verbatim. `alchemy init` selects Pint with the `laravel` preset automatically in a Laravel project, and ports an existing `pint.json` completely:

```yaml
lint:
  provider: pint
  preset: laravel
```

Pint's runtime flags forward through `--flags` (`composer run fmt -- --flags=dirty` only fixes uncommitted files), and on the analysis side a Laravel project running `composer run analyse` gets [Larastan](https://github.com/larastan/larastan) installed and wired in automatically — phpstan that actually understands facades, Eloquent and container magic.

Alchemy can also manage [Rector](https://getrector.com) for automated refactoring. Add a `refactor` section and run `composer run refactor` (or `-- --check` in CI to fail on pending refactors — add `refactor` to `actions.run` to generate the workflow):

```yaml
refactor:
  php: '8.2' # upgrade sets targeting this PHP version (true = read from composer.json)
  sets: # any of rector 2's twenty prepared sets, kebab-cased
    - dead-code
    - code-quality
    - type-declarations
    - phpunit-code-quality
  skip:
    - src/legacy
```

A tool that rewrites your code should be opted into: rector only runs when a `refactor` section exists in your `alchemy.yml` (`refactor` supports `paths`, `downgrade`, `fluent-new-line` and `import-names` too — everything a typical `rector.php` expresses).

Alchemy can generate CI for more than GitHub Actions. Set one or more providers under `actions.provider` and the same yml projects onto each — `.github/workflows/*.yml`, `.gitlab-ci.yml` (with a PHP version matrix and composer caching), or `.circleci/config.yml`:

```yaml
actions:
  provider: # github is the default
    - github
    - gitlab
    - circleci
  run:
    - lint
    - tests
    - refactor
```

Lint and refactor jobs run in check mode on CI (they fail on violations instead of fixing); the auto-commit `lint.autofix` flow is GitHub-only.

Static analysis works the same way — add an `analyse` section and PHPStan is installed and configured on your first `composer run analyse`:

```yaml
analyse:
  level: 6
  ignore:
    - '#some error pattern to ignore#'
```

A `phpstan-baseline.neon` at your project root is included automatically, and **any other key under `analyse` is passed through to phpstan verbatim** — `includes`, `excludePaths`, `treatPhpDocTypesAsCertain`, anything — so the section is never less expressive than a hand-written neon file. Pest projects whose analyse paths cover their tests also get Pest's first-party phpstan plugin installed and wired in automatically (Pest 5 on PHP 8.4), so pest syntax analyses without false positives.

## ⚗️ Commands

| Command | What it does |
| --- | --- |
| `alchemy init` | Create alchemy.yml — detects your framework and asks whether to **port existing tool configs** (phpunit.xml, php-cs-fixer, pint.json, phpstan neon, rector.php) or keep them (`--port` / `--keep` to answer for every tool without prompts) |
| `alchemy test` | Run your tests (installs your engine on first run) |
| `alchemy lint` | Check code style — reports violations, changes nothing |
| `alchemy fmt` | Fix code style |
| `alchemy refactor` | Apply Rector refactors (`--check` in CI) |
| `alchemy analyse` | Run PHPStan static analysis |
| `alchemy ci` | Generate CI pipelines for your configured providers |
| `alchemy all` | Run **everything present in alchemy.yml** — tests, lint, refactor, analyse, CI generation. Sections that aren't in the file don't run |
| `alchemy switch <target>` | Switch CI provider (github/gitlab/circleci) or test engine (pest/phpunit) — everything regenerates from the same yml |
| `alchemy eject` | Leave alchemy: export real config files, no lock-in |

(`alchemy setup` is a deprecated alias for `alchemy all`.)

Coming from Alchemy 4? See the [upgrade guide](https://leafphp.dev/docs/utils/testing#upgrading-from-alchemy-4) — v4 scripts keep working, and the guide covers what changed.

Every tool is installed lazily: requiring alchemy adds nothing to your dependency tree until you actually run a command that needs an engine — pest arrives on your first `composer run test`, phpstan on your first `composer run analyse`, never before.

Alchemy also never takes over a setup you didn't hand it. When `init` finds an existing tool config it asks: **port it** into `alchemy.yml` (phpunit.xml, php-cs-fixer and pint configs map fully; phpstan neon files port through the `analyse` passthrough; `rector.php` is read directly from the rector config itself) — or **keep it**, recorded in `alchemy.yml` as a pinned file:

```yaml
tests: phpunit.xml # a string section = "run this tool from my file, as-is"
analyse: phpstan.dist.neon
```

A map section is alchemy-managed (config generated fresh per run inside `.alchemy/` and discarded after — only caches stay; the root is never touched); a string section runs the engine against your file verbatim. Either way the decision lives in `alchemy.yml`, so CI and teammates get the same behavior. As a safety net, a tool with no section at all but an existing config file in the project still runs on that file. And `alchemy eject` exports real config files if you ever want to leave.

The typical workflow, fresh or existing project:

```bash
leaf install alchemy   # or composer require leafs/alchemy --dev
./vendor/bin/alchemy init
composer run test      # or lint / fmt / analyse / refactor / ci
```

`composer run alchemy` maps to `alchemy all` for when you want the whole pipeline locally before pushing.

Based on your engine, you might see either of the outputs below

- PEST PHP

<img width="307" alt="image" src="https://user-images.githubusercontent.com/26604242/182198978-1b8e2ba2-42e7-4345-82d0-3ae5be35d299.png">

- PHPUnit

<img width="770" alt="image" src="https://user-images.githubusercontent.com/26604242/182198446-47a4a581-3aa4-470c-b450-420604b9bb6c.png">
