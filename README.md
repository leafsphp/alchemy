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
    processUncoveredFiles: true

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

actions:
  run:
    - 'lint'
    - 'test'
  php:
    extensions: json, zip
    versions:
      - '8.3'
  event:
    - 'push'
    - 'pull_request'
```

You can make edits to this file to suit your needs. The `app` key is an array of directories to look for your app files in. The `tests` key is an array of configurations for your tests. The `lint` key is an array of configurations for your code styling checks. Once you're done setting up your `alchemy.yml` file, you can run the setup script.

```bash
leaf run alchemy # or composer run alchemy
```

This will install your test engine, PHP CS Fixer and any other dependencies you might need, and then generate dummy tests using the test engine you chose. It will then lint your code, run your tests and generate a coverage report (if you selected that option). It will also add a `test` and `lint` command to your `composer.json` file which you can use to run your tests and lint your code respectively. Finally, it will generate a `.github/workflows` directory with a `test.yml` file and a `lint.yml` file which you can use to run your tests and linting on github actions.

Based on your engine, you might see either of the outputs below

- PEST PHP

<img width="307" alt="image" src="https://user-images.githubusercontent.com/26604242/182198978-1b8e2ba2-42e7-4345-82d0-3ae5be35d299.png">

- PHPUnit

<img width="770" alt="image" src="https://user-images.githubusercontent.com/26604242/182198446-47a4a581-3aa4-470c-b450-420604b9bb6c.png">
