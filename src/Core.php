<?php

namespace Leaf\Alchemy;

/**
 * Container for Alchemy config
 */
class Core
{
    protected static $config = [];

    /**
     * Set Alchemy config
     */
    public static function set($config)
    {
        self::$config = $config;
    }

    /**
     * Get Alchemy config
     */
    public static function get($key = null)
    {
        if ($key) {
            return self::$config[$key] ?? null;
        }

        return self::$config;
    }

    public static function unJsonify($data, $pretty = JSON_PRETTY_PRINT)
    {
        $parsed = str_replace(['{', '}', '\/', ':', '"__DIR__"'], ['[', ']', '/', ' =>', '__DIR__'], json_encode($data, $pretty));
        return preg_replace('/"__DIR__\s*\.\s*\'(.*?)\'"/', '__DIR__ . \'$1\'', $parsed);
    }

    public static function generateTestFiles()
    {
        $config = static::get();

        if (file_exists(getcwd() . '/.alchemy/.phpunit.result.cache')) {
            \Leaf\FS\File::move(getcwd() . '/.alchemy/.phpunit.result.cache', getcwd() . '/.phpunit.result.cache');
        }

        $testsConfig = $config['tests'] ?? [];
        $appPathsConfig = $config['app'] ?? [__DIR__];

        // root attributes: sane defaults + verbatim passthrough of tests.config,
        // so any phpunit.xml attribute maps 1:1 without alchemy needing to know it
        $attributeOverrides = $testsConfig['config'] ?? [];

        if (isset($attributeOverrides['xmlnxsi'])) {
            $attributeOverrides['xmlns:xsi'] = $attributeOverrides['xmlnxsi'];
            unset($attributeOverrides['xmlnxsi']);
        }

        $attributes = array_merge([
            'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:noNamespaceSchemaLocation' => './vendor/phpunit/phpunit/phpunit.xsd',
            'bootstrap' => 'vendor/autoload.php',
            'colors' => true,
            'cacheDirectory' => '.alchemy',
        ], $attributeOverrides);

        $renderedAttributes = [];

        foreach ($attributes as $attribute => $value) {
            $renderedAttributes[] = $attribute . '="' . static::xmlValue($value) . '"';
        }

        $renderedAttributes = implode("\n         ", $renderedAttributes);

        // testsuites: simple `paths` list, or fully named suites with
        // per-suite paths/files/exclude under `tests.suites`
        $filePatterns = (array) ($testsConfig['files'] ?? ['*.test.php']);
        $suitesConfig = $testsConfig['suites'] ?? null;

        if (!$suitesConfig) {
            $suitesConfig = [];

            foreach ($testsConfig['paths'] ?? ['tests'] as $testSuiteDir) {
                $suitesConfig["Test Suite $testSuiteDir"] = ['paths' => [$testSuiteDir]];
            }
        }

        $testSuites = '';

        foreach ($suitesConfig as $suiteName => $suite) {
            $suite = is_array($suite) ? $suite : ['paths' => [$suite]];
            $entries = '';

            foreach ((array) ($suite['paths'] ?? []) as $suiteDir) {
                foreach ((array) ($suite['files'] ?? $filePatterns) as $pattern) {
                    $suffix = ltrim($pattern, '*');
                    $entries .= "<directory suffix=\"$suffix\">$suiteDir</directory>";
                }
            }

            foreach ((array) ($suite['exclude'] ?? []) as $excluded) {
                $entries .= "<exclude>$excluded</exclude>";
            }

            $testSuites .= "<testsuite name=\"$suiteName\">$entries</testsuite>";
        }

        // coverage source
        $coverageIncludes = '';
        $coverageExcludes = '';

        foreach ($appPathsConfig as $appDir) {
            $coverageIncludes .= "<directory suffix=\".php\">$appDir</directory>";
        }

        foreach ((array) ($testsConfig['coverage']['exclude'] ?? []) as $excluded) {
            $tag = substr($excluded, -4) === '.php' ? 'file' : 'directory';
            $coverageExcludes .= "<$tag>$excluded</$tag>";
        }

        if ($coverageExcludes) {
            $coverageExcludes = "<exclude>$coverageExcludes</exclude>";
        }

        // phpunit extensions
        $extensions = '';

        foreach ((array) ($testsConfig['extensions'] ?? []) as $extensionClass) {
            $extensions .= "<bootstrap class=\"$extensionClass\"/>";
        }

        if ($extensions) {
            $extensions = "<extensions>$extensions</extensions>";
        }

        // <php> block: env/ini/const/server/get/post/cookie
        $phpBlock = '';
        $phpBlockConfig = [
            'env' => $testsConfig['env'] ?? [],
            'ini' => $testsConfig['ini'] ?? [],
            'const' => $testsConfig['const'] ?? [],
            'server' => $testsConfig['server'] ?? [],
            'get' => $testsConfig['get'] ?? [],
            'post' => $testsConfig['post'] ?? [],
            'cookie' => $testsConfig['cookie'] ?? [],
        ];

        if (array_filter($phpBlockConfig)) {
            $phpBlock = '<php>';

            foreach ($phpBlockConfig as $tag => $values) {
                foreach ($values as $name => $value) {
                    $phpBlock .= "<$tag name=\"$name\" value=\"" . static::xmlValue($value) . '"/>';
                }
            }

            $phpBlock .= '</php>';
        }

        $phpunitXml = \Leaf\FS\File::read(__DIR__ . '/setup/stubs/phpunit.xml.stub');
        $phpunitXml = str_replace(
            ['CONFIG.ATTRIBUTES', 'CONFIG.TESTSUITES', 'COVERAGE.INCLUDES', 'COVERAGE.EXCLUDES', 'CONFIG.PHPBLOCK', 'CONFIG.EXTENSIONS'],
            [$renderedAttributes, $testSuites, $coverageIncludes, $coverageExcludes, $phpBlock, $extensions],
            $phpunitXml
        );

        \Leaf\FS\File::create(getcwd() . '/phpunit.xml', $phpunitXml, ['overwrite' => true]);
    }

    protected static function xmlValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES);
    }

    /**
     * Wire alchemy's commands into the project's composer scripts
     */
    public static function installComposerScripts()
    {
        $appComposerJson = json_decode(file_get_contents(getcwd() . '/composer.json'), true);

        $composerConfig = $appComposerJson['config'] ?? [];
        $composerConfigPlugins = $composerConfig['allow-plugins'] ?? [];

        $appComposerJson['scripts']['alchemy'] = './vendor/bin/alchemy setup';
        $appComposerJson['scripts']['test'] = './vendor/bin/alchemy test';
        $appComposerJson['scripts']['lint'] = './vendor/bin/alchemy lint';
        $appComposerJson['scripts']['fmt'] = './vendor/bin/alchemy fmt';
        $appComposerJson['scripts']['refactor'] = './vendor/bin/alchemy refactor';
        $appComposerJson['scripts']['analyse'] = './vendor/bin/alchemy analyse';
        $appComposerJson['scripts']['ci'] = './vendor/bin/alchemy ci';

        $appComposerJson['config'] = array_merge($composerConfig, [
            'allow-plugins' => array_merge($composerConfigPlugins, [
                'pestphp/pest-plugin' => true,
            ]),
        ]);

        file_put_contents(getcwd() . '/composer.json', json_encode($appComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function updateGitIgnore()
    {
        $appGitIgnoreFile = getcwd() . '/.gitignore';
        $gitIgnoreContent = file_exists($appGitIgnoreFile) ? file_get_contents($appGitIgnoreFile) : '';

        if (strpos($gitIgnoreContent, '.alchemy') === false) {
            file_put_contents($appGitIgnoreFile, "\n# Alchemy\n.alchemy\n", FILE_APPEND);
        }

        if (strpos($gitIgnoreContent, '.phpunit.result.cache') === false) {
            file_put_contents($appGitIgnoreFile, ".phpunit.result.cache\n", FILE_APPEND);
        }
    }

    public static function generateAnalyseFiles()
    {
        $config = static::get();
        $analyseConfig = $config['analyse'] ?? [];

        $level = $analyseConfig['level'] ?? 5;
        $paths = (array) ($analyseConfig['paths'] ?? $config['app'] ?? ['src']);

        $neon = "parameters:\n";
        $neon .= "    level: $level\n";
        $neon .= "    tmpDir: .alchemy/phpstan\n";
        $neon .= "    paths:\n";

        foreach ($paths as $path) {
            $neon .= "        - $path\n";
        }

        if (!empty($analyseConfig['ignore'])) {
            $neon .= "    ignoreErrors:\n";

            foreach ((array) $analyseConfig['ignore'] as $pattern) {
                $neon .= "        - '" . str_replace("'", "''", $pattern) . "'\n";
            }
        }

        \Leaf\FS\File::create(getcwd() . '/.phpstan.dist.neon', $neon, ['overwrite' => true]);
    }

    public static function generateRefactorFiles()
    {
        $config = static::get();
        $refactorConfig = $config['refactor'] ?? [];

        $paths = [];

        foreach ((array) ($config['app'] ?? []) as $appDir) {
            $paths[] = "__DIR__ . '/$appDir'";
        }

        if (!$paths) {
            $paths = ['__DIR__'];
        }

        $chain = '';

        // target php version: explicit ('8.2') or true to read composer.json
        $phpVersion = $refactorConfig['php'] ?? null;

        if ($phpVersion === true) {
            $chain .= "\n    ->withPhpSets()";
        } elseif ($phpVersion) {
            $chain .= "\n    ->withPhpSets(php" . str_replace('.', '', (string) $phpVersion) . ': true)';
        }

        // rector's prepared sets, kebab-cased in yml
        $setMap = [
            'dead-code' => 'deadCode',
            'code-quality' => 'codeQuality',
            'coding-style' => 'codingStyle',
            'type-declarations' => 'typeDeclarations',
            'privatization' => 'privatization',
            'naming' => 'naming',
            'instanceof' => 'instanceOf',
            'early-return' => 'earlyReturn',
            'strict-booleans' => 'strictBooleans',
        ];
        $preparedSets = [];

        foreach ((array) ($refactorConfig['sets'] ?? []) as $set) {
            if (isset($setMap[$set])) {
                $preparedSets[] = $setMap[$set] . ': true';
            } else {
                trigger_error("Unknown rector set \"$set\" in alchemy.yml. Available sets: " . implode(', ', array_keys($setMap)));
            }
        }

        if ($preparedSets) {
            $chain .= "\n    ->withPreparedSets(" . implode(', ', $preparedSets) . ')';
        }

        // skip entries: rule classes or paths
        $skips = [];

        foreach ((array) ($refactorConfig['skip'] ?? []) as $skip) {
            $skips[] = strpos($skip, '\\') !== false
                ? '\\' . ltrim($skip, '\\') . '::class'
                : "__DIR__ . '/$skip'";
        }

        if ($skips) {
            $chain .= "\n    ->withSkip([" . implode(', ', $skips) . '])';
        }

        // extra rule classes, verbatim
        $rules = [];

        foreach ((array) ($refactorConfig['rules'] ?? []) as $rule) {
            $rules[] = '\\' . ltrim($rule, '\\') . '::class';
        }

        if ($rules) {
            $chain .= "\n    ->withRules([" . implode(', ', $rules) . '])';
        }

        $rectorDist = \Leaf\FS\File::read(__DIR__ . '/setup/stubs/rector.php.stub');
        $rectorDist = str_replace(
            ['REFACTOR.PATHS', 'REFACTOR.CHAIN'],
            ['[' . implode(', ', $paths) . ']', $chain],
            $rectorDist
        );

        \Leaf\FS\File::create(getcwd() . '/.rector.dist.php', $rectorDist, ['overwrite' => true]);
    }

    public static function generateLintFiles()
    {
        $config = static::get();
        $lintConfig = $config['lint'];

        if (file_exists(getcwd() . '/.alchemy/.php-cs-fixer.cache')) {
            \Leaf\FS\File::move(getcwd() . '/.alchemy/.php-cs-fixer.cache', getcwd() . '/.php-cs-fixer.cache');
        }

        $lintRules = $lintConfig['rules'] ?? [];
        $lintPreset = $lintConfig['preset'] ?? 'PSR12';

        $ignoreTests = json_encode($lintConfig['ignore_tests'] ?? false, JSON_PRETTY_PRINT);
        $ignoreVCFiles = json_encode($lintConfig['ignore_vc_files'] ?? true, JSON_PRETTY_PRINT);
        $ignoreDotFiles = json_encode($lintConfig['ignore_dot_files'] ?? true, JSON_PRETTY_PRINT);

        $appPathsConfig = $config['app'] ? array_merge($config['app'], $ignoreTests ? [] : ($config['tests']['paths'] ?? [])) : null;
        $lintParallel = ($lintConfig['parallel'] ?? false) ? "\n->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())" : '';

        $lintPaths = [];
        $lintRules["@$lintPreset"] = true;

        if ($appPathsConfig) {
            foreach ($appPathsConfig as $appDir) {
                $lintPaths[] = "__DIR__ . '/$appDir'";
            }
        } else {
            $lintPaths = ['__DIR__'];
        }

        $lintExcludes = '';

        if (!empty($lintConfig['exclude'])) {
            $lintExcludes = "\n  ->exclude(" . static::unJsonify((array) $lintConfig['exclude']) . ')';
        }

        $phpcsFixerDist = \Leaf\FS\File::read(__DIR__ . '/setup/stubs/.php_cs.dist.php.stub');
        $phpcsFixerDist = str_replace(
            ['LINT.PATHS', 'LINT.IGNORE_DOT_FILES', 'LINT.IGNORE_VC_FILES', 'LINT.RULES', 'LINT.PARALLEL', 'LINT.EXCLUDES'],
            [static::unJsonify($lintPaths), $ignoreDotFiles, $ignoreVCFiles, static::unJsonify($lintRules), $lintParallel, $lintExcludes],
            $phpcsFixerDist
        );

        \Leaf\FS\File::create(getcwd() . '/.php_cs.dist.php', $phpcsFixerDist, ['overwrite' => true]);
    }
}
