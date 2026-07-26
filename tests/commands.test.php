<?php

beforeEach(function () {
    sandboxSetup();
});

afterEach(function () {
    sandboxTeardown();
});

test('failing commands exit non-zero so CI can gate on them', function () {
    writeComposerJson();

    // guard failure: no alchemy.yml yet
    [$exit, $output] = alchemy('switch gitlab');

    expect($exit)->toBe(1)
        ->and($output)->toContain('No alchemy.yml found');

    // validation failure: unknown target
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n");
    [$exit, $output] = alchemy('switch bogus-target');

    expect($exit)->toBe(1)
        ->and($output)->toContain('Unknown target');
});

test('init creates alchemy.yml and wires composer scripts', function () {
    writeComposerJson();
    mkdir(getcwd() . '/src');

    [$exit, $output] = alchemy('init');

    expect($exit)->toBe(0)
        ->and(file_exists(getcwd() . '/alchemy.yml'))->toBeTrue();

    $composerJson = json_decode(file_get_contents(getcwd() . '/composer.json'), true);

    expect($composerJson['scripts']['test'] ?? null)->toContain('alchemy test')
        ->and($composerJson['scripts']['lint'] ?? null)->toContain('alchemy lint')
        ->and($composerJson['scripts']['fmt'] ?? null)->toContain('alchemy fmt');
});

test('init refuses to overwrite an existing alchemy.yml without --force', function () {
    writeComposerJson();
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n");

    [$exit, $output] = alchemy('init');

    expect($exit)->toBe(1)
        ->and($output)->toContain('--force');
});

test('init imports an existing phpunit.xml into alchemy.yml', function () {
    writeComposerJson(['require' => ['laravel/framework' => '^11.0']]);
    mkdir(getcwd() . '/app');
    file_put_contents(getcwd() . '/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" bootstrap="vendor/autoload.php" colors="true" stopOnFailure="true">
    <testsuites>
        <testsuite name="Unit"><directory suffix="Test.php">tests/Unit</directory></testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing"/>
    </php>
</phpunit>
XML);

    [$exit, $output] = alchemy('init');
    $yml = file_get_contents(getcwd() . '/alchemy.yml');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Imported existing phpunit.xml')
        ->and($output)->toContain('Laravel')
        ->and($yml)->toContain('Unit:')
        ->and($yml)->toContain("- '*Test.php'")
        ->and($yml)->toContain('APP_ENV: testing')
        ->and($yml)->toContain('stopOnFailure: true');
});

test('ci generates pipelines for every configured provider', function () {
    writeComposerJson();
    file_put_contents(getcwd() . '/alchemy.yml', <<<'YML'
app:
  - src

tests:
  engine: pest

lint:
  preset: PSR12

actions:
  provider:
    - github
    - gitlab
    - circleci
  run:
    - lint
    - tests
  events:
    - pull_request
YML);

    [$exit] = alchemy('ci');

    expect($exit)->toBe(0)
        ->and(file_exists(getcwd() . '/.github/workflows/lint.yml'))->toBeTrue()
        ->and(file_exists(getcwd() . '/.github/workflows/tests.yml'))->toBeTrue()
        ->and(file_exists(getcwd() . '/.gitlab-ci.yml'))->toBeTrue()
        ->and(file_exists(getcwd() . '/.circleci/config.yml'))->toBeTrue();

    expect(file_get_contents(getcwd() . '/.github/workflows/lint.yml'))->toContain('composer run lint');
    expect(file_get_contents(getcwd() . '/.gitlab-ci.yml'))->toContain('merge_request_event');
    expect(file_get_contents(getcwd() . '/.circleci/config.yml'))->toContain('version: 2.1');
});

test('switch moves ci providers and cleans up old files', function () {
    writeComposerJson();
    file_put_contents(getcwd() . '/alchemy.yml', <<<'YML'
app:
  - src

tests:
  engine: pest

actions:
  run:
    - tests
YML);

    alchemy('ci');
    expect(file_exists(getcwd() . '/.github/workflows/tests.yml'))->toBeTrue();

    [$exit, $output] = alchemy('switch gitlab --clean');

    expect($exit)->toBe(0)
        ->and(file_exists(getcwd() . '/.gitlab-ci.yml'))->toBeTrue()
        ->and(file_exists(getcwd() . '/.github/workflows/tests.yml'))->toBeFalse()
        ->and(file_get_contents(getcwd() . '/alchemy.yml'))->toContain('provider: gitlab');
});

test('switch changes the test engine and explains next steps', function () {
    writeComposerJson();
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n\ntests:\n  engine: pest\n");

    [$exit, $output] = alchemy('switch phpunit');

    expect($exit)->toBe(0)
        ->and(file_get_contents(getcwd() . '/alchemy.yml'))->toContain('engine: phpunit')
        ->and($output)->toContain('composer remove pestphp/pest');
});

test('refactor without config is a friendly no-op', function () {
    writeComposerJson();
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n\ntests:\n  engine: pest\n");

    [$exit, $output] = alchemy('refactor');

    expect($exit)->toBe(0)
        ->and($output)->toContain('No `refactor` section');
});

test('eject exports real config files and rewires composer scripts', function () {
    writeComposerJson(['scripts' => ['alchemy' => '@php vendor/bin/alchemy setup']]);
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n\ntests:\n  engine: pest\n\nlint:\n  preset: PSR12\n");

    [$exit] = alchemy('eject');

    expect($exit)->toBe(0)
        ->and(file_exists(getcwd() . '/phpunit.xml'))->toBeTrue()
        ->and(file_exists(getcwd() . '/.php-cs-fixer.dist.php'))->toBeTrue();

    $composerJson = json_decode(file_get_contents(getcwd() . '/composer.json'), true);

    expect($composerJson['scripts']['test'])->toContain('pest')
        ->and($composerJson['scripts'])->not->toHaveKey('alchemy');

    [$secondExit, $secondOutput] = alchemy('eject');

    expect($secondExit)->toBe(1)
        ->and($secondOutput)->toContain('--force');
});
