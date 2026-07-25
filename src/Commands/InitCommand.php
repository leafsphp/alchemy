<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Alchemy\Core;
use Leaf\Sprout\Command;
use Symfony\Component\Yaml\Yaml;

class InitCommand extends Command
{
    protected $signature = 'init
        {--f|force? : Overwrite an existing alchemy.yml}';
    protected $description = 'Set up alchemy: detect your framework and import existing phpunit/php-cs-fixer config';
    protected $help = 'Creates an alchemy.yml for your project. Detects your framework from composer.json, imports an existing phpunit.xml and php-cs-fixer config if found, and wires up composer scripts.';

    protected function handle(): int
    {
        $configFile = getcwd() . '/alchemy.yml';

        if (file_exists($configFile) && !$this->option('force')) {
            $this->writeln('<error>alchemy.yml already exists. Re-run with --force to overwrite.</error>');
            return 1;
        }

        $config = [
            'app' => $this->detectAppPaths(),
            'tests' => [
                'engine' => file_exists(getcwd() . '/vendor/bin/phpunit') && !file_exists(getcwd() . '/vendor/bin/pest') ? 'phpunit' : 'pest',
            ],
            'lint' => [
                'preset' => 'PSR12',
            ],
            'actions' => [
                'run' => ['lint', 'tests'],
                'events' => ['push', 'pull_request'],
            ],
        ];

        if ($imported = $this->importPhpunitXml($config)) {
            $config = $imported;
            $this->writeln('<info>Imported existing phpunit.xml into alchemy.yml.</info>');
        }

        if ($imported = $this->importCsFixerConfig($config)) {
            $config = $imported;
            $this->writeln('<info>Imported existing php-cs-fixer config into alchemy.yml.</info>');
        }

        \Leaf\FS\File::create($configFile, Yaml::dump($config, 6, 2), ['overwrite' => true]);

        Core::installComposerScripts();
        Core::updateGitIgnore();

        $this->writeln('<info>alchemy.yml created for your ' . $this->detectFramework() . ' project.</info>');
        $this->writeln('<comment>Run `composer run test`, `composer run lint`, or `composer run alchemy` to get started.</comment>');

        return 0;
    }

    protected function detectFramework(): string
    {
        $composerJson = json_decode(@file_get_contents(getcwd() . '/composer.json'), true) ?? [];
        $deps = array_merge($composerJson['require'] ?? [], $composerJson['require-dev'] ?? []);

        if (isset($deps['laravel/framework'])) {
            return 'Laravel';
        }

        if (isset($deps['symfony/framework-bundle'])) {
            return 'Symfony';
        }

        if (isset($deps['slim/slim'])) {
            return 'Slim';
        }

        if (isset($deps['leafs/leaf']) || isset($deps['leafs/mvc-core'])) {
            return 'Leaf';
        }

        return 'PHP';
    }

    protected function detectAppPaths(): array
    {
        $framework = $this->detectFramework();
        $candidates = [
            'Laravel' => ['app'],
            'Symfony' => ['src'],
            'Slim' => ['src'],
            'Leaf' => ['app', 'src'],
            'PHP' => [],
        ][$framework];

        // frameworkless: fall back to composer autoload dirs
        if (!$candidates) {
            $composerJson = json_decode(@file_get_contents(getcwd() . '/composer.json'), true) ?? [];

            foreach (($composerJson['autoload']['psr-4'] ?? []) as $dir) {
                $candidates = array_merge($candidates, (array) $dir);
            }

            $candidates = array_map(fn ($dir) => rtrim($dir, '/'), $candidates) ?: ['src'];
        }

        $paths = array_values(array_filter($candidates, fn ($dir) => is_dir(getcwd() . "/$dir")));

        return $paths ?: ['src'];
    }

    protected function importPhpunitXml(array $config): ?array
    {
        $phpunitFile = getcwd() . '/phpunit.xml';

        if (!file_exists($phpunitFile)) {
            $phpunitFile = getcwd() . '/phpunit.xml.dist';
        }

        if (!file_exists($phpunitFile)) {
            return null;
        }

        $xml = @simplexml_load_file($phpunitFile);

        if (!$xml) {
            $this->writeln('<comment>Found a phpunit.xml but could not parse it — skipping import.</comment>');
            return null;
        }

        // root attributes → tests.config (passthrough), minus the ones alchemy defaults
        $skipAttributes = ['xmlns:xsi', 'xsi:noNamespaceSchemaLocation', 'colors', 'cacheDirectory', 'cacheResultFile'];

        foreach ($xml->attributes() as $name => $value) {
            if (in_array((string) $name, $skipAttributes)) {
                continue;
            }

            $config['tests']['config'][(string) $name] = $this->castXmlValue((string) $value);
        }

        // namespaced attributes (xsi) come through a separate iteration; skip them wholesale

        // testsuites → tests.suites
        foreach ($xml->testsuites->testsuite ?? [] as $suite) {
            $suiteName = (string) $suite['name'];
            $suiteEntry = [];

            foreach ($suite->directory ?? [] as $directory) {
                $suiteEntry['paths'][] = (string) $directory;
                $suffix = (string) ($directory['suffix'] ?? 'Test.php');
                $suiteEntry['files'][] = "*$suffix";
            }

            foreach ($suite->exclude ?? [] as $excluded) {
                $suiteEntry['exclude'][] = (string) $excluded;
            }

            if (isset($suiteEntry['files'])) {
                $suiteEntry['files'] = array_values(array_unique($suiteEntry['files']));
            }

            if ($suiteEntry) {
                $config['tests']['suites'][$suiteName] = $suiteEntry;
            }
        }

        // <php> block → env/ini/const/server/get/post/cookie
        foreach (['env', 'ini', 'const', 'server', 'get', 'post', 'cookie'] as $tag) {
            foreach ($xml->php->{$tag} ?? [] as $entry) {
                $config['tests'][$tag][(string) $entry['name']] = $this->castXmlValue((string) $entry['value']);
            }
        }

        // <source>/<coverage> include → app paths, exclude → coverage.exclude
        $source = $xml->source ?? $xml->coverage ?? null;

        if ($source) {
            $includes = [];

            foreach ($source->include->directory ?? [] as $directory) {
                $includes[] = (string) $directory;
            }

            if ($includes) {
                $config['app'] = $includes;
            }

            foreach ($source->exclude->directory ?? [] as $directory) {
                $config['tests']['coverage']['exclude'][] = (string) $directory;
            }

            foreach ($source->exclude->file ?? [] as $file) {
                $config['tests']['coverage']['exclude'][] = (string) $file;
            }
        }

        return $config;
    }

    protected function importCsFixerConfig(array $config): ?array
    {
        $fixerFile = null;

        foreach (['.php-cs-fixer.dist.php', '.php-cs-fixer.php'] as $candidate) {
            if (file_exists(getcwd() . "/$candidate")) {
                $fixerFile = getcwd() . "/$candidate";
                break;
            }
        }

        if (!$fixerFile) {
            return null;
        }

        // the fixer config is executable PHP that needs php-cs-fixer's classes —
        // load the project's autoloader in case alchemy runs from outside it
        if (!class_exists(\PhpCsFixer\Config::class) && file_exists(getcwd() . '/vendor/autoload.php')) {
            require_once getcwd() . '/vendor/autoload.php';
        }

        if (!class_exists(\PhpCsFixer\Config::class)) {
            $this->writeln('<comment>Found a php-cs-fixer config but php-cs-fixer isn\'t installed — run `composer run lint` once, then `alchemy init --force` to import it.</comment>');
            return null;
        }

        try {
            $fixerConfig = require $fixerFile;
        } catch (\Throwable $exception) {
            $this->writeln('<comment>Could not load ' . basename($fixerFile) . ' — skipping import. (' . $exception->getMessage() . ')</comment>');
            return null;
        }

        if (!$fixerConfig instanceof \PhpCsFixer\Config) {
            return null;
        }

        $rules = $fixerConfig->getRules();

        foreach ($rules as $rule => $value) {
            if (strpos($rule, '@') === 0 && $value === true) {
                $config['lint']['preset'] = ltrim($rule, '@');
                unset($rules[$rule]);
                break;
            }
        }

        if ($rules) {
            $config['lint']['rules'] = $rules;
        }

        $config['lint']['risky'] = $fixerConfig->getRiskyAllowed();

        return $config;
    }

    protected function castXmlValue(string $value)
    {
        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }

        return $value;
    }
}
