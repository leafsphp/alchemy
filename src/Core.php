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
        $parsed = str_replace(['{', '}', '\/', ':', '"__DIR__"', '"dirname(__DIR__)"'], ['[', ']', '/', ' =>', '__DIR__', 'dirname(__DIR__)'], json_encode($data, $pretty));
        return preg_replace('/"((?:dirname\(__DIR__\)|__DIR__)\s*\.\s*)\'(.*?)\'"/', '$1\'$2\'', $parsed);
    }

    public static function generateTestFiles(bool $ejected = false)
    {
        $config = static::get();

        // heal residue from interrupted runs of older alchemy versions —
        // but never during eject, whose whole point is root config files
        if (!$ejected) {
            static::cleanStaleRootConfigs();
        }

        // generated configs live inside .alchemy so the project root stays
        // clean — even if a run is killed halfway. eject writes real,
        // portable configs to the root on purpose.
        $root = $ejected ? '' : getcwd() . '/';

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
            'xsi:noNamespaceSchemaLocation' => $root . './vendor/phpunit/phpunit/phpunit.xsd',
            // phpunit resolves these relative to the config file, so the
            // in-.alchemy config gets absolute paths
            'bootstrap' => $root . 'vendor/autoload.php',
            'colors' => true,
            'cacheDirectory' => $root . '.alchemy',
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
                    $entries .= "<directory suffix=\"$suffix\">$root$suiteDir</directory>";
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
            $coverageIncludes .= "<directory suffix=\".php\">$root$appDir</directory>";
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

        if (!$ejected) {
            static::ensureAlchemyDir();
        }

        \Leaf\FS\File::create(
            $ejected ? getcwd() . '/phpunit.xml' : getcwd() . '/.alchemy/phpunit.xml',
            $phpunitXml,
            ['overwrite' => true]
        );
    }

    /**
     * Older alchemy versions generated configs at the project root and cleaned
     * them after each run — a killed run could leave them behind. Remove any
     * such residue (only files carrying alchemy's generation signature).
     */
    /**
     * Generated configs live inside .alchemy — make sure it exists.
     */
    protected static function ensureAlchemyDir(): void
    {
        if (!is_dir(getcwd() . '/.alchemy')) {
            mkdir(getcwd() . '/.alchemy', 0777, true);
        }
    }

    public static function cleanStaleRootConfigs(): void
    {
        $rootPhpunit = getcwd() . '/phpunit.xml';

        if (file_exists($rootPhpunit) && strpos((string) file_get_contents($rootPhpunit), 'cacheDirectory=".alchemy"') !== false) {
            \Leaf\FS\File::delete($rootPhpunit);
        }

        // these dotted names were only ever alchemy-generated
        foreach (['.php_cs.dist.php', '.rector.dist.php', '.phpstan.dist.neon'] as $staleConfig) {
            if (file_exists(getcwd() . '/' . $staleConfig)) {
                \Leaf\FS\File::delete(getcwd() . '/' . $staleConfig);
            }
        }
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

        // @php keeps the scripts working on Windows too
        $appComposerJson['scripts']['alchemy'] = '@php vendor/bin/alchemy all';
        $appComposerJson['scripts']['test'] = '@php vendor/bin/alchemy test';
        $appComposerJson['scripts']['lint'] = '@php vendor/bin/alchemy lint';
        $appComposerJson['scripts']['fmt'] = '@php vendor/bin/alchemy fmt';
        $appComposerJson['scripts']['refactor'] = '@php vendor/bin/alchemy refactor';
        $appComposerJson['scripts']['analyse'] = '@php vendor/bin/alchemy analyse';
        $appComposerJson['scripts']['ci'] = '@php vendor/bin/alchemy ci';

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
        $root = getcwd();

        $neon = '';

        // a phpstan baseline is a list of known, accepted errors — respect the
        // conventional file, or whatever `analyse.baseline` points at
        $baseline = $analyseConfig['baseline'] ?? 'phpstan-baseline.neon';

        $includes = [];

        if ($baseline && file_exists("$root/$baseline")) {
            $includes[] = "$root/$baseline";
        }

        foreach ((array) ($analyseConfig['includes'] ?? []) as $include) {
            $includes[] = strpos($include, '/') === 0 ? $include : "$root/$include";
        }

        // the baseline may also be listed explicitly under `includes`
        $includes = array_unique($includes);

        if ($includes) {
            $neon .= "includes:\n";

            foreach ($includes as $include) {
                $neon .= "    - $include\n";
            }
        }

        $neon .= "parameters:\n";
        $neon .= "    level: $level\n";
        $neon .= "    tmpDir: $root/.alchemy/phpstan\n";
        $neon .= "    paths:\n";

        foreach ($paths as $path) {
            $neon .= "        - $root/$path\n";
        }

        if (!empty($analyseConfig['excludePaths'])) {
            $neon .= "    excludePaths:\n";

            foreach ((array) $analyseConfig['excludePaths'] as $path) {
                $neon .= '        - ' . (strpos($path, '/') === 0 ? $path : "$root/$path") . "\n";
            }
        }

        if (!empty($analyseConfig['ignore'])) {
            $neon .= "    ignoreErrors:\n";

            foreach ((array) $analyseConfig['ignore'] as $pattern) {
                $neon .= "        - '" . str_replace("'", "''", $pattern) . "'\n";
            }
        }

        // any other key under `analyse` is passed straight through as a
        // phpstan parameter — the section is not limited to the keys above
        $handled = ['level', 'paths', 'ignore', 'baseline', 'includes', 'excludePaths'];
        $extras = array_diff_key($analyseConfig, array_flip($handled));

        if ($extras) {
            $neon .= static::neonBlock($extras, 1);
        }

        static::ensureAlchemyDir();
        \Leaf\FS\File::create(getcwd() . '/.alchemy/.phpstan.dist.neon', $neon, ['overwrite' => true]);
    }

    /**
     * Render a config array as an indented neon block
     */
    protected static function neonBlock(array $data, int $depth): string
    {
        $indent = str_repeat('    ', $depth);
        $isList = array_keys($data) === range(0, count($data) - 1);
        $block = '';

        foreach ($data as $key => $value) {
            $label = $isList ? "$indent-" : "$indent$key:";

            if (is_array($value)) {
                $block .= "$label\n" . static::neonBlock($value, $depth + 1);
            } else {
                $block .= "$label " . static::neonValue($value) . "\n";
            }
        }

        return $block;
    }

    /**
     * Render a scalar as a neon value
     */
    protected static function neonValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    public static function generateRefactorFiles()
    {
        $config = static::get();
        $refactorConfig = $config['refactor'] ?? [];

        $paths = [];

        // generated config lives in .alchemy — project root is one level up
        foreach ((array) ($refactorConfig['paths'] ?? $config['app'] ?? []) as $appDir) {
            $paths[] = "dirname(__DIR__) . '/$appDir'";
        }

        if (!$paths) {
            $paths = ['dirname(__DIR__)'];
        }

        $chain = '';

        // target php version: explicit ('8.2') or true to read composer.json
        $phpVersion = $refactorConfig['php'] ?? null;

        if ($phpVersion === true) {
            $chain .= "\n    ->withPhpSets()";
        } elseif ($phpVersion) {
            $chain .= "\n    ->withPhpSets(php" . str_replace('.', '', (string) $phpVersion) . ': true)';
        }

        // downgrade target: rewrite syntax DOWN to an older php version
        if (!empty($refactorConfig['downgrade'])) {
            $chain .= "\n    ->withDowngradeSets(php" . str_replace('.', '', (string) $refactorConfig['downgrade']) . ': true)';
        }

        if (!empty($refactorConfig['fluent-new-line'])) {
            $chain .= "\n    ->withFluentCallNewLine()";
        }

        // import-names: true enables the lot; a map tunes individual flags
        $importNames = $refactorConfig['import-names'] ?? null;

        if ($importNames) {
            $importFlags = is_array($importNames) ? $importNames : [];
            $asBool = function ($value) {
                return $value ? 'true' : 'false';
            };

            $chain .= "\n    ->withImportNames("
                . 'importNames: ' . $asBool($importFlags['import'] ?? true)
                . ', importDocBlockNames: ' . $asBool($importFlags['doc-blocks'] ?? true)
                . ', importShortClasses: ' . $asBool($importFlags['short-classes'] ?? true)
                . ', removeUnusedImports: ' . $asBool($importFlags['remove-unused'] ?? true)
                . ')';
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
                : "dirname(__DIR__) . '/$skip'";
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

        static::ensureAlchemyDir();
        \Leaf\FS\File::create(getcwd() . '/.alchemy/.rector.dist.php', $rectorDist, ['overwrite' => true]);
    }

    public static function generateLintFiles(bool $ejected = false)
    {
        $config = static::get();
        $lintConfig = $config['lint'];

        if (!$ejected) {
            static::cleanStaleRootConfigs();
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

        // the generated config lives in .alchemy, so the project root is one
        // level up from __DIR__; ejected configs sit at the root itself
        $rootExpr = $ejected ? '__DIR__' : 'dirname(__DIR__)';

        if ($appPathsConfig) {
            foreach ($appPathsConfig as $appDir) {
                $lintPaths[] = "$rootExpr . '/$appDir'";
            }
        } else {
            $lintPaths = [$rootExpr];
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

        if (!$ejected) {
            // keep the fixer cache inside .alchemy too
            $phpcsFixerDist = str_replace(
                '->setFinder($finder);',
                "->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')\n  ->setFinder(\$finder);",
                $phpcsFixerDist
            );
        }

        if (!$ejected) {
            static::ensureAlchemyDir();
        }

        \Leaf\FS\File::create(
            $ejected ? getcwd() . '/.php_cs.dist.php' : getcwd() . '/.alchemy/.php_cs.dist.php',
            $phpcsFixerDist,
            ['overwrite' => true]
        );
    }
}
