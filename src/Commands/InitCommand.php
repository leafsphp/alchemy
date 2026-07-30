<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Alchemy\Core;
use Leaf\Sprout\Command;
use Symfony\Component\Yaml\Yaml;

class InitCommand extends Command
{
    protected $signature = 'init
        {--f|force? : Overwrite an existing alchemy.yml}
        {--p|port? : Port existing tool configs into alchemy.yml without asking}
        {--k|keep? : Keep existing tool configs as-is without asking}';
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

        // existing tool configs: ask port-or-keep, and record the choice in
        // alchemy.yml — a string value pins the tool to that file as-is
        $config = $this->resolveExistingConfig($config, 'tests', ['phpunit.xml', 'phpunit.xml.dist'], function ($cfg) {
            return $this->importPhpunitXml($cfg);
        });

        $config = $this->resolveExistingConfig($config, 'lint', ['.php-cs-fixer.dist.php', '.php-cs-fixer.php'], function ($cfg) {
            return $this->importCsFixerConfig($cfg);
        });

        $config = $this->resolveExistingConfig($config, 'analyse', ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'], function ($cfg, $file) {
            return $this->importPhpstanConfig($cfg, $file);
        });

        $config = $this->resolveExistingConfig($config, 'refactor', ['rector.php', 'rector.dist.php'], function ($cfg, $file) {
            return $this->importRectorConfig($cfg, $file);
        });

        \Leaf\FS\File::create($configFile, Yaml::dump($config, 6, 2) . $this->optionalSectionsHint($config), ['overwrite' => true]);

        Core::installComposerScripts();
        Core::updateGitIgnore();

        $this->writeln('<info>alchemy.yml created for your ' . $this->detectFramework() . ' project.</info>');
        $this->writeln('<comment>Run `composer run test`, `composer run lint`, or `composer run alchemy` to get started.</comment>');

        return 0;
    }

    /**
     * A tool config file already exists: ask whether to port it into
     * alchemy.yml or keep using the file as-is. The answer is recorded —
     * a ported section is a map, a kept file is pinned as a string value.
     * Falls back to pinning when the import can't represent the file.
     */
    protected function resolveExistingConfig(array $config, string $tool, array $candidates, callable $import): array
    {
        $file = null;

        foreach ($candidates as $candidate) {
            if (file_exists(getcwd() . "/$candidate")) {
                $file = $candidate;
                break;
            }
        }

        if (!$file) {
            return $config;
        }

        $port = $this->option('port') ? true : ($this->option('keep') ? false : null);

        if ($port === null && !$this->isInteractive()) {
            $this->writeln("<comment>Found $file — keeping it as-is (`$tool: $file`). Re-run `alchemy init` interactively or with --port to port it.</comment>");
            $config[$tool] = $file;
            return $config;
        }

        if ($port === false || ($port === null && !sprout()->confirm("Found $file — port it into alchemy.yml? (\"no\" keeps running $tool from $file)", true))) {
            $config[$tool] = $file;
            return $config;
        }

        if ($imported = $import($config, $file)) {
            $this->parkPortedConfig($file);
            return $imported;
        }

        $this->writeln("<comment>Couldn't fully port $file — keeping it as-is (`$tool: $file`).</comment>");
        $config[$tool] = $file;

        return $config;
    }

    /**
     * A ported config's job is done — park the original inside .alchemy as a
     * .bak so the project root is clean without the user deleting anything
     */
    protected function parkPortedConfig(string $file): void
    {
        if (!is_dir(getcwd() . '/.alchemy')) {
            mkdir(getcwd() . '/.alchemy', 0777, true);
        }

        if (@rename(getcwd() . "/$file", getcwd() . "/.alchemy/$file.bak")) {
            $this->writeln("<info>Imported $file into alchemy.yml — original parked at .alchemy/$file.bak.</info>");
        } else {
            $this->writeln("<info>Imported $file into alchemy.yml — you can delete $file now.</info>");
        }
    }

    protected function isInteractive(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    /**
     * Commented examples of the optional sections, appended to a fresh
     * alchemy.yml so the full feature set is discoverable
     */
    protected function optionalSectionsHint(array $config = []): string
    {
        $hints = '';

        if (!isset($config['analyse'])) {
            $hints .= <<<'YML'

# static analysis with phpstan — uncomment to enable
# (set to a filename, e.g. `analyse: phpstan.neon`, to run from your own config;
# any other key here is passed through to phpstan verbatim)
# analyse:
#   level: 5
#   baseline: phpstan-baseline.neon

YML;
        }

        if (!isset($config['refactor'])) {
            $hints .= <<<'YML'

# automated refactoring with rector — uncomment to enable
# (set to a filename, e.g. `refactor: rector.php`, to run from your own config)
# refactor:
#   php: '8.3'
#   sets:
#     - dead-code
#     - code-quality
#   import-names: true

YML;
        }

        return $hints;
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

    /**
     * Port a phpstan neon config into an analyse section. Neon is yaml-shaped
     * (tabs aside), and unknown analyse keys pass through to phpstan verbatim,
     * so most files map 1:1. Returns null when the file can't be parsed.
     */
    protected function importPhpstanConfig(array $config, string $file): ?array
    {
        try {
            // neon allows tabs for indentation, yaml doesn't
            $parsed = Yaml::parse(str_replace("\t", '    ', (string) file_get_contents(getcwd() . "/$file")));
        } catch (\Throwable $exception) {
            $this->writeln('<comment>Could not parse ' . $file . ' as yaml-compatible neon. (' . $exception->getMessage() . ')</comment>');
            return null;
        }

        if (!is_array($parsed)) {
            return null;
        }

        $analyse = $parsed['parameters'] ?? [];

        // includes: the baseline gets its own key, the rest carry over
        foreach ((array) ($parsed['includes'] ?? []) as $include) {
            if (strpos(basename($include), 'baseline') !== false) {
                $analyse['baseline'] = $include;
            } else {
                $analyse['includes'][] = $include;
            }
        }

        // anything outside includes/parameters (services, rules, extensions)
        // is phpstan-extension territory alchemy can't own
        if (array_diff_key($parsed, array_flip(['includes', 'parameters']))) {
            $this->writeln("<comment>$file has sections beyond includes/parameters.</comment>");
            return null;
        }

        $config['analyse'] = $analyse ?: ['level' => 5];

        return $config;
    }

    /**
     * Port a rector.php into a refactor section by loading the config and
     * reading the builder's settings. Best-effort: anything that doesn't map
     * to refactor keys returns null so the file gets pinned instead.
     */
    protected function importRectorConfig(array $config, string $file): ?array
    {
        if (!class_exists(\Rector\Config\RectorConfig::class) && file_exists(getcwd() . '/vendor/autoload.php')) {
            require_once getcwd() . '/vendor/autoload.php';
        }

        if (!class_exists(\Rector\Config\RectorConfig::class)) {
            $this->writeln('<comment>Rector isn\'t installed, so ' . $file . ' can\'t be read.</comment>');
            return null;
        }

        try {
            $builder = require getcwd() . "/$file";

            if (!$builder instanceof \Rector\Configuration\RectorConfigBuilder) {
                return null;
            }

            $settings = [];

            foreach ((new \ReflectionObject($builder))->getProperties() as $property) {
                $property->setAccessible(true);
                $settings[$property->getName()] = $property->getValue($builder);
            }
        } catch (\Throwable $exception) {
            $this->writeln('<comment>Could not load ' . $file . '. (' . $exception->getMessage() . ')</comment>');
            return null;
        }

        $refactor = [];
        $root = getcwd() . '/';

        foreach ((array) ($settings['paths'] ?? []) as $path) {
            $refactor['paths'][] = strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
        }

        // sets are file paths into rector's config/set tree; map the ones
        // alchemy speaks and bail on anything else
        $knownSets = array_keys(Core::REFACTOR_SETS);

        foreach ((array) ($settings['sets'] ?? []) as $set) {
            $setName = basename((string) $set, '.php');

            if (in_array($setName, $knownSets)) {
                $refactor['sets'][] = $setName;
            } elseif (preg_match('/^(?:php|up-to-php)(\d)(\d+)$/', $setName, $phpSet)) {
                $refactor['php'] = "$phpSet[1].$phpSet[2]";
            } elseif (preg_match('/^(?:downgrade-php|down-to-php)(\d)(\d+)$/', $setName, $downgradeSet)) {
                $refactor['downgrade'] = "$downgradeSet[1].$downgradeSet[2]";
            } else {
                $this->writeln("<comment>$file uses a set alchemy can't express ($setName).</comment>");
                return null;
            }
        }

        // php sets registered via withPhpSets rather than explicit set files
        if (!isset($refactor['php']) && !empty($settings['isWithPhpSetsUsed'])) {
            $refactor['php'] = true;
        }

        foreach ((array) ($settings['skip'] ?? []) as $skip) {
            $refactor['skip'][] = is_string($skip) && strpos($skip, $root) === 0 ? substr($skip, strlen($root)) : $skip;
        }

        foreach ((array) ($settings['rules'] ?? []) as $rule) {
            $refactor['rules'][] = $rule;
        }

        if (!empty($settings['isFluentNewLine']) || !empty($settings['fluentCallNewLine'])) {
            $refactor['fluent-new-line'] = true;
        }

        if (!empty($settings['importNames']) || !empty($settings['removeUnusedImports'])) {
            $refactor['import-names'] = [
                'import' => (bool) ($settings['importNames'] ?? false),
                'doc-blocks' => (bool) ($settings['importDocBlockNames'] ?? false),
                'short-classes' => (bool) ($settings['importShortClasses'] ?? false),
                'remove-unused' => (bool) ($settings['removeUnusedImports'] ?? false),
            ];

            if (!in_array(false, $refactor['import-names'], true)) {
                $refactor['import-names'] = true;
            }
        }

        $config['refactor'] = $refactor ?: ['php' => true];

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
