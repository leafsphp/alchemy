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

        $appPathsConfig = $config['app'] ?? [__DIR__];
        $testSuiteConfig = $config['tests']['paths'] ?? ['tests'];
        $testCoverageFiles = $config['tests']['coverage']['processUncoveredFiles'] ?? true;
        $xmlnsXsi = $config['tests']['config']['xmlnxsi'] ?? 'http://www.w3.org/2001/XMLSchema-instance';
        $nsLocation = $config['tests']['config']['xsi:noNamespaceSchemaLocation'] ?? './vendor/phpunit/phpunit/phpunit.xsd';
        $bootstrap = $config['tests']['config']['bootstrap'] ?? 'vendor/autoload.php';
        $colors = $config['tests']['config']['colors'] ?? true;

        $testSuites = '';
        $coverageIncludes = '';

        foreach ($testSuiteConfig as $testSuiteDir) {
            $testSuites .= "<testsuite name=\"Test Suite $testSuiteDir\"><directory suffix=\".test.php\">$testSuiteDir</directory></testsuite>";
        }

        foreach ($appPathsConfig as $appDir) {
            $coverageIncludes .= "<directory suffix=\".php\">$appDir</directory>";
        }

        $phpunitXml = \Leaf\FS::readFile(__DIR__ . '/setup/stubs/phpunit.xml.stub');
        $phpunitXml = str_replace(
            ['CONFIG.XMLNSXSI', 'CONFIG.NONSLOCATION', 'CONFIG.BOOTSTRAP', 'CONFIG.COLORS', 'CONFIG.TESTSUITES', 'COVERAGE.PROCESSUNCOVEREDFILES', 'COVERAGE.INCLUDES'],
            [$xmlnsXsi, $nsLocation, $bootstrap, $colors ? 'true' : 'false', $testSuites, $testCoverageFiles ? 'true' : 'false', $coverageIncludes],
            $phpunitXml
        );

        \Leaf\FS::writeFile(getcwd() . '/phpunit.xml', $phpunitXml);
    }
    public static function generateLintFiles()
    {
        $config = static::get();

        if (file_exists(getcwd() . '/.alchemy/.php-cs-fixer.cache')) {
            \Leaf\FS::moveFile(getcwd() . '/.alchemy/.php-cs-fixer.cache', getcwd() . '/.php-cs-fixer.cache');
        }

        $appPathsConfig = $config['app'] ?? null;
        $lintConfig = $config['lint'];
        $lintRules = $lintConfig['rules'] ?? [];
        $lintPreset = $lintConfig['preset'] ?? 'PSR12';
        $lintParallel = ($lintConfig['parallel'] ?? false) ? "\n->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())" : '';
        $ignoreDotFiles = json_encode($lintConfig['ignore_dot_files'] ?? true, JSON_PRETTY_PRINT);
        $ignoreVCFiles = json_encode($lintConfig['ignore_vc_files'] ?? true, JSON_PRETTY_PRINT);

        $lintPaths = [];
        $lintRules["@$lintPreset"] = true;

        if ($appPathsConfig) {
            foreach ($appPathsConfig as $appDir) {
                $lintPaths[] = "__DIR__ . '/$appDir'";
            }
        } else {
            $lintPaths = ['__DIR__'];
        }

        $phpcsFixerDist = \Leaf\FS::readFile(__DIR__ . '/setup/stubs/.php_cs.dist.php.stub');
        $phpcsFixerDist = str_replace(
            ['LINT.PATHS', 'LINT.IGNORE_DOT_FILES', 'LINT.IGNORE_VC_FILES', 'LINT.RULES', 'LINT.PARALLEL'],
            [static::unJsonify($lintPaths), $ignoreDotFiles, $ignoreVCFiles, static::unJsonify($lintRules), $lintParallel],
            $phpcsFixerDist
        );

        \Leaf\FS::writeFile(getcwd() . '/.php_cs.dist.php', $phpcsFixerDist);
    }
}
