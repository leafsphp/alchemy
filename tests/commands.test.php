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

    [$exit, $output] = alchemy('init --port');
    $yml = file_get_contents(getcwd() . '/alchemy.yml');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Imported phpunit.xml')
        ->and($output)->toContain('Laravel')
        ->and($yml)->toContain('Unit:')
        ->and($yml)->toContain("- '*Test.php'")
        ->and($yml)->toContain('APP_ENV: testing')
        ->and($yml)->toContain('stopOnFailure: true');
});

test('init records use-as-is choices as pinned string sections', function () {
    writeComposerJson();
    mkdir(getcwd() . '/src');
    file_put_contents(getcwd() . '/phpunit.xml', "<?xml version=\"1.0\"?>\n<phpunit></phpunit>\n");
    file_put_contents(getcwd() . '/phpstan.dist.neon', "parameters:\n    level: 8\n");
    file_put_contents(getcwd() . '/rector.php', "<?php\nreturn true;\n");

    [$exit, $output] = alchemy('init --keep');
    $yml = file_get_contents(getcwd() . '/alchemy.yml');

    expect($exit)->toBe(0)
        ->and($yml)->toContain('tests: phpunit.xml')
        ->and($yml)->toContain('analyse: phpstan.dist.neon')
        ->and($yml)->toContain('refactor: rector.php');
});

test('init --port turns a phpstan neon into an analyse section', function () {
    writeComposerJson();
    mkdir(getcwd() . '/src');
    file_put_contents(getcwd() . '/phpstan.dist.neon', <<<'NEON'
includes:
	- vendor/phpstan/phpstan/conf/bleedingEdge.neon
	- phpstan-baseline.neon

parameters:
	level: 8
	paths:
		- src
	treatPhpDocTypesAsCertain: false
NEON);

    [$exit, $output] = alchemy('init --port');
    $yml = file_get_contents(getcwd() . '/alchemy.yml');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Imported phpstan.dist.neon')
        ->and($yml)->toContain('level: 8')
        ->and($yml)->toContain('baseline: phpstan-baseline.neon')
        ->and($yml)->toContain('- vendor/phpstan/phpstan/conf/bleedingEdge.neon')
        ->and($yml)->toContain('treatPhpDocTypesAsCertain: false');
});

test('tests run from a pinned phpunit.xml string section', function () {
    writeComposerJson();
    linkVendor();
    mkdir(getcwd() . '/tests');
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n\ntests: phpunit.xml\n");
    file_put_contents(getcwd() . '/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Pinned"><directory suffix="MyTest.php">tests</directory></testsuite>
    </testsuites>
</phpunit>
XML);
    file_put_contents(getcwd() . '/tests/ExampleMyTest.php', "<?php\n\ntest('runs from the pinned config', function () {\n    expect(true)->toBeTrue();\n});\n");

    [$exit, $output] = alchemy('test');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Using your existing phpunit.xml')
        ->and($output)->toContain('runs from the pinned config');
})->skip(PHP_OS_FAMILY === 'Windows', 'vendor symlink not reliable on Windows runners');

test('standing tests.flags reach the engine invocation', function () {
    writeComposerJson();
    linkVendor();
    mkdir(getcwd() . '/tests');
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n\ntests:\n  engine: pest\n  flags:\n    - stop-on-failure\n");
    file_put_contents(getcwd() . '/tests/flag.test.php', "<?php\n\ntest('flag run works', function () {\n    expect(true)->toBeTrue();\n});\n");

    [$exit, $output] = alchemy('test');

    expect($exit)->toBe(0)
        ->and($output)->toContain('flag run works')
        ->and(file_exists(getcwd() . '/.alchemy/phpunit.xml'))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows', 'vendor symlink not reliable on Windows runners');

test('all runs exactly the sections present in alchemy.yml', function () {
    writeComposerJson();
    linkVendor();
    mkdir(getcwd() . '/src');
    file_put_contents(getcwd() . '/src/Fine.php', "<?php\n\n\$x = 'single';\n");
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n\nlint:\n  preset: PSR12\n");

    [$exit, $output] = alchemy('all');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Running linter')
        ->and($output)->not->toContain('Using pest')
        ->and($output)->toContain('Everything in alchemy.yml ran clean')
        ->and(file_exists(getcwd() . '/.alchemy/.php_cs.dist.php'))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows', 'vendor symlink not reliable on Windows runners');

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

test('lint respects an existing php-cs-fixer config when alchemy.yml has no lint section', function () {
    writeComposerJson();
    linkVendor();
    mkdir(getcwd() . '/src');
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n");
    file_put_contents(getcwd() . '/.php-cs-fixer.dist.php', <<<'PHP'
<?php

$finder = PhpCsFixer\Finder::create()->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
    ->setRules(['single_quote' => true])
    ->setFinder($finder);
PHP);
    $userConfigBefore = file_get_contents(getcwd() . '/.php-cs-fixer.dist.php');
    file_put_contents(getcwd() . '/src/Bad.php', "<?php\n\n\$x = \"double\";\n");

    [$exit, $output] = alchemy('lint');

    expect($exit)->toBe(1)
        ->and($output)->toContain('Using your existing .php-cs-fixer.dist.php')
        ->and(file_exists(getcwd() . '/.php_cs.dist.php'))->toBeFalse()
        ->and(file_get_contents(getcwd() . '/src/Bad.php'))->toContain('"double"');

    [$fmtExit] = alchemy('fmt');

    expect($fmtExit)->toBe(0)
        ->and(file_get_contents(getcwd() . '/src/Bad.php'))->toContain("'double'")
        ->and(file_get_contents(getcwd() . '/.php-cs-fixer.dist.php'))->toBe($userConfigBefore);
})->skip(PHP_OS_FAMILY === 'Windows', 'vendor symlink not reliable on Windows runners');

test('tests respect an existing phpunit.xml when alchemy.yml has no tests section', function () {
    writeComposerJson();
    linkVendor();
    mkdir(getcwd() . '/tests');
    file_put_contents(getcwd() . '/alchemy.yml', "app:\n  - src\n");
    file_put_contents(getcwd() . '/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Mine"><directory suffix="MyTest.php">tests</directory></testsuite>
    </testsuites>
</phpunit>
XML);
    $userConfigBefore = file_get_contents(getcwd() . '/phpunit.xml');
    file_put_contents(getcwd() . '/tests/ExampleMyTest.php', "<?php\n\ntest('runs on the user suite', function () {\n    expect(true)->toBeTrue();\n});\n");

    [$exit, $output] = alchemy('test');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Using your existing phpunit.xml')
        ->and($output)->toContain('runs on the user suite')
        ->and(file_get_contents(getcwd() . '/phpunit.xml'))->toBe($userConfigBefore)
        ->and(file_exists(getcwd() . '/.alchemy/phpunit.xml.user'))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows', 'vendor symlink not reliable on Windows runners');
