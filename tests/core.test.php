<?php

use Leaf\Alchemy\Core;

beforeEach(function () {
    sandboxSetup();
    Core::set([]);
});

afterEach(function () {
    sandboxTeardown();
});

test('generated phpunit config uses the .test.php suffix by default', function () {
    Core::set(['app' => ['src'], 'tests' => ['paths' => ['tests']]]);
    Core::generateTestFiles();

    $xml = file_get_contents(getcwd() . '/phpunit.xml');

    expect($xml)->toContain('suffix=".test.php"')
        ->toContain('<testsuite name="Test Suite tests">')
        ->toContain('<directory suffix=".php">src</directory>');
});

test('named suites render with per-suite files and excludes', function () {
    Core::set([
        'app' => ['src'],
        'tests' => [
            'suites' => [
                'Unit' => ['paths' => ['tests/unit'], 'exclude' => ['tests/unit/legacy']],
                'Feature' => ['paths' => ['tests/feature'], 'files' => ['*Test.php']],
            ],
        ],
    ]);
    Core::generateTestFiles();

    $xml = file_get_contents(getcwd() . '/phpunit.xml');

    expect($xml)->toContain('<testsuite name="Unit"><directory suffix=".test.php">tests/unit</directory><exclude>tests/unit/legacy</exclude></testsuite>')
        ->toContain('<testsuite name="Feature"><directory suffix="Test.php">tests/feature</directory></testsuite>');
});

test('phpunit root attributes pass through verbatim from tests.config', function () {
    Core::set([
        'app' => ['src'],
        'tests' => [
            'config' => ['stopOnFailure' => true, 'executionOrder' => 'random'],
        ],
    ]);
    Core::generateTestFiles();

    $xml = file_get_contents(getcwd() . '/phpunit.xml');

    expect($xml)->toContain('stopOnFailure="true"')
        ->toContain('executionOrder="random"')
        ->toContain('bootstrap="vendor/autoload.php"')
        ->toContain('cacheDirectory=".alchemy"');
});

test('the php block renders env, ini and server values', function () {
    Core::set([
        'app' => ['src'],
        'tests' => [
            'env' => ['APP_ENV' => 'testing'],
            'ini' => ['memory_limit' => '512M'],
            'server' => ['MY_FLAG' => 'on'],
        ],
    ]);
    Core::generateTestFiles();

    $xml = file_get_contents(getcwd() . '/phpunit.xml');

    expect($xml)->toContain('<env name="APP_ENV" value="testing"/>')
        ->toContain('<ini name="memory_limit" value="512M"/>')
        ->toContain('<server name="MY_FLAG" value="on"/>');
});

test('coverage excludes distinguish files from directories', function () {
    Core::set([
        'app' => ['src'],
        'tests' => ['coverage' => ['exclude' => ['src/legacy', 'src/Skipped.php']]],
    ]);
    Core::generateTestFiles();

    $xml = file_get_contents(getcwd() . '/phpunit.xml');

    expect($xml)->toContain('<exclude><directory>src/legacy</directory><file>src/Skipped.php</file></exclude>');
});

test('generators overwrite their previous output', function () {
    Core::set(['app' => ['src'], 'tests' => []]);
    Core::generateTestFiles();
    Core::set(['app' => ['lib'], 'tests' => []]);
    Core::generateTestFiles();

    expect(file_get_contents(getcwd() . '/phpunit.xml'))
        ->toContain('<directory suffix=".php">lib</directory>')
        ->not->toContain('<directory suffix=".php">src</directory>');
});

test('lint config renders preset, rules and excludes', function () {
    Core::set([
        'app' => ['src'],
        'lint' => [
            'preset' => 'PSR12',
            'exclude' => ['legacy'],
            'rules' => ['single_quote' => true],
        ],
    ]);
    Core::generateLintFiles();

    $config = file_get_contents(getcwd() . '/.php_cs.dist.php');

    expect($config)->toContain('"@PSR12" => true')
        ->toContain('"single_quote" => true')
        ->toContain('->exclude(')
        ->toContain("__DIR__ . '/src'");
});

test('rector config renders php sets, prepared sets, skips and rules', function () {
    Core::set([
        'app' => ['src'],
        'refactor' => [
            'php' => '8.2',
            'sets' => ['dead-code', 'code-quality'],
            'skip' => ['src/legacy', 'Rector\Php80\Rector\Class_\SomeRector'],
            'rules' => ['Rector\Php81\Rector\Class_\OtherRector'],
        ],
    ]);
    Core::generateRefactorFiles();

    $config = file_get_contents(getcwd() . '/.rector.dist.php');

    expect($config)->toContain('->withPhpSets(php82: true)')
        ->toContain('->withPreparedSets(deadCode: true, codeQuality: true)')
        ->toContain("__DIR__ . '/src/legacy'")
        ->toContain('\Rector\Php80\Rector\Class_\SomeRector::class')
        ->toContain('->withRules([\Rector\Php81\Rector\Class_\OtherRector::class])');
});

test('phpstan config renders level, paths and ignores', function () {
    Core::set([
        'app' => ['src', 'lib'],
        'analyse' => ['level' => 7, 'ignore' => ['#unknown method#']],
    ]);
    Core::generateAnalyseFiles();

    $neon = file_get_contents(getcwd() . '/.phpstan.dist.neon');

    expect($neon)->toContain('level: 7')
        ->toContain("- src\n")
        ->toContain("- lib\n")
        ->toContain("- '#unknown method#'")
        ->toContain('tmpDir: .alchemy/phpstan');
});
