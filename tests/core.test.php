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

    $xml = file_get_contents(getcwd() . '/.alchemy/phpunit.xml');

    expect($xml)->toContain('suffix=".test.php"')
        ->toContain('<testsuite name="Test Suite tests">')
        ->toContain('<directory suffix=".php">' . getcwd() . '/src</directory>');
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

    $xml = file_get_contents(getcwd() . '/.alchemy/phpunit.xml');

    expect($xml)->toContain('<testsuite name="Unit"><directory suffix=".test.php">' . getcwd() . '/tests/unit</directory><exclude>tests/unit/legacy</exclude></testsuite>')
        ->toContain('<testsuite name="Feature"><directory suffix="Test.php">' . getcwd() . '/tests/feature</directory></testsuite>');
});

test('phpunit root attributes pass through verbatim from tests.config', function () {
    Core::set([
        'app' => ['src'],
        'tests' => [
            'config' => ['stopOnFailure' => true, 'executionOrder' => 'random'],
        ],
    ]);
    Core::generateTestFiles();

    $xml = file_get_contents(getcwd() . '/.alchemy/phpunit.xml');

    expect($xml)->toContain('stopOnFailure="true"')
        ->toContain('executionOrder="random"')
        ->toContain('bootstrap="' . getcwd() . '/vendor/autoload.php"')
        ->toContain('cacheDirectory="' . getcwd() . '/.alchemy"');
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

    $xml = file_get_contents(getcwd() . '/.alchemy/phpunit.xml');

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

    $xml = file_get_contents(getcwd() . '/.alchemy/phpunit.xml');

    expect($xml)->toContain('<exclude><directory>src/legacy</directory><file>src/Skipped.php</file></exclude>');
});

test('generators overwrite their previous output', function () {
    Core::set(['app' => ['src'], 'tests' => []]);
    Core::generateTestFiles();
    Core::set(['app' => ['lib'], 'tests' => []]);
    Core::generateTestFiles();

    expect(file_get_contents(getcwd() . '/.alchemy/phpunit.xml'))
        ->toContain('<directory suffix=".php">' . getcwd() . '/lib</directory>')
        ->not->toContain('<directory suffix=".php">' . getcwd() . '/src</directory>');
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

    $config = file_get_contents(getcwd() . '/.alchemy/.php_cs.dist.php');

    expect($config)->toContain('"@PSR12" => true')
        ->toContain('"single_quote" => true')
        ->toContain('->exclude(')
        ->toContain("dirname(__DIR__) . '/src'")
        ->toContain('->setCacheFile(');
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

    $config = file_get_contents(getcwd() . '/.alchemy/.rector.dist.php');

    expect($config)->toContain('->withPhpSets(php82: true)')
        ->toContain('->withPreparedSets(deadCode: true, codeQuality: true)')
        ->toContain("dirname(__DIR__) . '/src/legacy'")
        ->toContain('\Rector\Php80\Rector\Class_\SomeRector::class')
        ->toContain('->withRules([\Rector\Php81\Rector\Class_\OtherRector::class])');
});

test('rector config renders downgrade, fluent new line, import names and custom paths', function () {
    Core::set([
        'app' => ['src'],
        'refactor' => [
            'paths' => ['src', 'tests'],
            'downgrade' => '8.0',
            'fluent-new-line' => true,
            'import-names' => true,
        ],
    ]);
    Core::generateRefactorFiles();

    $config = file_get_contents(getcwd() . '/.alchemy/.rector.dist.php');

    expect($config)->toContain('->withDowngradeSets(php80: true)')
        ->toContain('->withFluentCallNewLine()')
        ->toContain('->withImportNames(importNames: true, importDocBlockNames: true, importShortClasses: true, removeUnusedImports: true)')
        ->toContain("dirname(__DIR__) . '/src', dirname(__DIR__) . '/tests'");

    expect(array_keys(Core::REFACTOR_SETS))->toContain('phpunit-code-quality', 'rector-preset', 'symfony-code-quality', 'named-args', 'carbon');
});

test('phpstan config renders level, paths and ignores', function () {
    Core::set([
        'app' => ['src', 'lib'],
        'analyse' => ['level' => 7, 'ignore' => ['#unknown method#']],
    ]);
    Core::generateAnalyseFiles();

    $neon = file_get_contents(getcwd() . '/.alchemy/.phpstan.dist.neon');

    expect($neon)->toContain('level: 7')
        ->toContain('- ' . getcwd() . "/src\n")
        ->toContain('- ' . getcwd() . "/lib\n")
        ->toContain("- '#unknown method#'")
        ->toContain('tmpDir: ' . getcwd() . '/.alchemy/phpstan');
});

test('phpstan config includes the pest plugin when installed without extension-installer', function () {
    mkdir(getcwd() . '/vendor/pestphp/pest-plugin-phpstan', 0777, true);
    file_put_contents(getcwd() . '/vendor/pestphp/pest-plugin-phpstan/extension.neon', "services: []\n");

    Core::set(['app' => ['src'], 'analyse' => ['level' => 5]]);
    Core::generateAnalyseFiles();

    expect(file_get_contents(getcwd() . '/.alchemy/.phpstan.dist.neon'))
        ->toContain('- ' . getcwd() . "/vendor/pestphp/pest-plugin-phpstan/extension.neon\n");

    // extension-installer wires the plugin itself — no manual include then
    mkdir(getcwd() . '/vendor/phpstan/extension-installer', 0777, true);
    Core::generateAnalyseFiles();

    expect(file_get_contents(getcwd() . '/.alchemy/.phpstan.dist.neon'))
        ->not->toContain('pest-plugin-phpstan');
});

test('phpstan config passes unknown analyse keys through as parameters', function () {
    Core::set([
        'app' => ['src'],
        'analyse' => [
            'level' => 8,
            'includes' => ['vendor/phpstan/phpstan/conf/bleedingEdge.neon'],
            'excludePaths' => ['tests'],
            'reportUnmatchedIgnoredErrors' => true,
            'treatPhpDocTypesAsCertain' => false,
            'universalObjectCratesClasses' => ['Leaf\Config'],
        ],
    ]);
    Core::generateAnalyseFiles();

    $neon = file_get_contents(getcwd() . '/.alchemy/.phpstan.dist.neon');

    expect($neon)->toContain('- ' . getcwd() . "/vendor/phpstan/phpstan/conf/bleedingEdge.neon\n")
        ->toContain("    excludePaths:\n        - " . getcwd() . "/tests\n")
        ->toContain("    reportUnmatchedIgnoredErrors: true\n")
        ->toContain("    treatPhpDocTypesAsCertain: false\n")
        ->toContain("    universalObjectCratesClasses:\n        - 'Leaf\\Config'\n");
});
