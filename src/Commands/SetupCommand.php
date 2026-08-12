<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Alchemy\Core;
use Leaf\Sprout\Command;
use Symfony\Component\Yaml\Yaml;

class SetupCommand extends Command
{
    protected $signature = 'setup
        {--l|lint? : Run only linter}
        {--t|test? : Run only tests}
        {--r|refactor? : Run only rector refactors}
        {--ci|actions? : Generate GitHub actions}
        {--c|check? : Check without changing anything (used in CI)}
        {--f|force? : Replace test or tests folder if it exists}
        {--flags= : Add flags to the command being run separated by commas}';
    protected $description = 'Setup work environment based on Alchemy configuration';
    protected $help = 'This command will help you setup your work environment based on the alchemy.yml configuration file.';

    /**
     * Forces check/fix behaviour for verb commands (lint = check, fmt = fix)
     */
    protected $modeOverride = null;

    protected function handle(): int
    {
        $this->loadAlchemyConfig();

        if ($this->option('test')) {
            set_time_limit(0);
            return $this->runTests();
        }

        if ($this->option('lint')) {
            return $this->runLinter();
        }

        if ($this->option('refactor')) {
            return $this->runRefactor();
        }

        if ($this->option('actions')) {
            return $this->generateActions();
        }

        if (!$this->option('test') && !$this->option('lint') && !$this->option('refactor') && !$this->option('actions')) {
            $this->writeln("<comment>`alchemy setup` is deprecated — use `alchemy all` to run everything in alchemy.yml.</comment>\n");

            if (!file_exists(getcwd() . '/alchemy.yml')) {
                $this->writeln('<comment>No alchemy.yml found. Run `alchemy init` first — `alchemy all` runs what your file configures.</comment>');
                return 1;
            }

            return $this->runAll();
        }

        return 0;
    }

    /**
     * Run every tool present in alchemy.yml — nothing more, nothing less.
     * A section's presence (map or pinned file) is what opts it in.
     */
    protected function runAll(): int
    {
        $status = 0;

        if (Core::get('tests')) {
            set_time_limit(0);
            $status = $this->runTests() ?: $status;
        }

        if (Core::get('lint')) {
            $status = $this->runLinter() ?: $status;
        }

        if (Core::get('refactor')) {
            $status = $this->runRefactor() ?: $status;
        }

        if (Core::get('analyse')) {
            $status = $this->runAnalyser() ?: $status;
        }

        if (Core::get('actions')) {
            $status = $this->generateActions() ?: $status;
        }

        if ($status === 0) {
            $this->writeln('<info>Everything in alchemy.yml ran clean. ✔</info>');
        }

        return $status;
    }

    /**
     * The project's own alchemy.yml (empty when running on bundled defaults) —
     * used to tell "user configured this tool" from "alchemy defaults apply"
     */
    protected $projectConfig = [];

    protected function loadAlchemyConfig()
    {
        $configFile = getcwd() . '/alchemy.yml';

        if (file_exists($configFile)) {
            $this->projectConfig = Yaml::parseFile($configFile) ?? [];
            Core::set($this->projectConfig);
        } else {
            $this->writeln('<comment>No alchemy.yml found, using default configuration. Run `alchemy init` to customize.</comment>');
            Core::set(Yaml::parseFile(dirname(__DIR__) . '/setup/alchemy.yml'));
        }

        Core::ensureAlchemyDir();
    }

    /**
     * Alchemy never migrates a tool you didn't hand it: when your alchemy.yml
     * doesn't configure a tool but the project has its own config file for it,
     * that file is used as-is.
     */
    protected function userEngineConfig(string $tool): ?string
    {
        if ($tool === 'lint' && empty($this->projectConfig['lint'])) {
            foreach (['.php-cs-fixer.php', '.php-cs-fixer.dist.php', 'pint.json'] as $file) {
                if (file_exists(getcwd() . "/$file")) {
                    return $file;
                }
            }
        }

        if ($tool === 'tests' && empty($this->projectConfig['tests']) && file_exists(getcwd() . '/phpunit.xml')) {
            return 'phpunit.xml';
        }

        return null;
    }

    /**
     * A section set to a string pins that tool to the named config file —
     * the recorded "use my file as-is" choice. A map is alchemy-managed.
     */
    protected function pinnedConfig(string $tool): ?string
    {
        $section = Core::get($tool);

        return (is_string($section) && $section !== '') ? $section : null;
    }

    protected function checkMode(): bool
    {
        if ($this->modeOverride !== null) {
            return $this->modeOverride === 'check';
        }

        return (bool) $this->option('check');
    }

    protected function runTests()
    {
        $config = is_array(Core::get('tests')) ? Core::get('tests') : [];
        $userConfig = $this->pinnedConfig('tests') ?? $this->userEngineConfig('tests');

        $engine = $config['engine'] ?? 'pest';
        $parallel = $config['parallel'] ?? false;

        // a pinned config file or a project with its own phpunit.xml and no
        // tests section runs on its own config
        if ($userConfig) {
            $engine = Core::detectTestEngine();
        }

        $engineInstaller = $engine === 'pest' ? '\'pestphp/pest:*\' --dev --with-all-dependencies' : '\'phpunit/phpunit:*\' --dev';

        if (!file_exists(getcwd() . "/vendor/bin/$engine")) {
            $this->writeln("<info>Setting up tests with $engine...</info>\n");

            if ($engine === 'pest') {
                $this->allowPestPlugin();
            }

            if (!sprout()->composer()->install($engineInstaller)->isSuccessful()) {
                $this->writeln("<error>Couldn\'t install $engine. Check your connection and try again.</error>");
                return 1;
            }

            $this->writeln("<info>$engine installed successfully!</info>");
        }

        if ($userConfig) {
            $this->writeln("<comment>  > Using your existing $userConfig...</comment>");

            $userFlags = '--configuration ' . getcwd() . "/$userConfig";
            $userFlags .= $engine === 'pest' ? ' --colors=always' : '';
            $userFlags .= $this->forwardedFlags();

            $testProcess = sprout()
                ->process(getcwd() . "/vendor/bin/$engine $userFlags")
                ->setTimeout(null)
                ->run(function ($type, $line): void {
                    $this->write($line);
                });

            if ($testProcess !== 0) {
                $this->writeln('<error>Tests failed. Check your code and try again.</error>');
                return 1;
            }

            return 0;
        }

        if (!\Leaf\FS\Directory::exists(getcwd() . '/' . ($config['paths'][0] ?? '/tests'))) {
            $this->writeln('<info>Writing sample tests...</info>');

            if (
                !\Leaf\FS\Directory::copy(
                    dirname(__DIR__) . "/setup/$engine",
                    getcwd(),
                    ['recursive' => true]
                )
            ) {
                $errors = json_encode(\Leaf\FS\Directory::errors(), JSON_PRETTY_PRINT);
                $this->writeln("<error>Couldn't write sample tests. $errors</error>");

                return 1;
            }
        }

        // the generated config lives in .alchemy/phpunit.xml — a hand-written
        // phpunit.xml at the root is never touched (it's only used when
        // alchemy.yml has no tests section, handled above)
        Core::generateTestFiles();

        $this->writeln("<comment>  > Using $engine for tests ...</comment>");

        $binary = $engine;
        $flags = $engine === 'pest' ? '--colors=always' : '';

        // standing engine flags from alchemy.yml — any pest/phpunit option
        // works here (tia, shard=1/4, type-coverage, ...)
        foreach ((array) ($config['flags'] ?? []) as $engineFlag) {
            $flags .= ' --' . ltrim((string) $engineFlag, '-');
        }

        $flags .= $this->forwardedFlags();

        if ($parallel) {
            $this->writeln('<info>  > Running tests in parallel...</info>');

            if ($engine === 'pest') {
                $flags .= ' --parallel';
            } else {
                // phpunit has no --parallel; paratest wraps it
                if (!file_exists(getcwd() . '/vendor/bin/paratest')) {
                    $this->writeln("<info>Setting up parallel testing with paratest...</info>\n");

                    if (!sprout()->composer()->install('brianium/paratest --dev')->isSuccessful()) {
                        $this->writeln('<error>Couldn\'t install paratest. Check your connection and try again.</error>');
                        return 1;
                    }
                }

                $binary = 'paratest';
            }
        }

        $testProcess = sprout()
            ->process(getcwd() . "/vendor/bin/$binary --configuration " . getcwd() . '/.alchemy/phpunit.xml' . ($flags ? " $flags" : ''))
            ->setTimeout(null)
            ->run(function ($type, $line): void {
                $this->write($line);
            });

        $this->discardGeneratedConfig('phpunit.xml');

        if ($testProcess !== 0) {
            $this->writeln('<error>Tests failed. Check your code and try again.</error>');
            return 1;
        }

        return 0;
    }

    /**
     * Generated configs are disposable — the run is over, only caches stay.
     * (Even a killed run leaves residue only inside gitignored .alchemy,
     * where the next run overwrites it.)
     */
    protected function discardGeneratedConfig(string $file): void
    {
        $generated = getcwd() . '/.alchemy/' . $file;

        if (file_exists($generated)) {
            \Leaf\FS\File::delete($generated);
        }
    }

    /**
     * Composer blocks the pest plugin with an interactive prompt unless it's trusted upfront
     */
    protected function allowPestPlugin()
    {
        $composerJsonFile = getcwd() . '/composer.json';
        $appComposerJson = json_decode(file_get_contents($composerJsonFile), true);

        if ($appComposerJson['config']['allow-plugins']['pestphp/pest-plugin'] ?? false) {
            return;
        }

        $appComposerJson['config']['allow-plugins']['pestphp/pest-plugin'] = true;

        file_put_contents($composerJsonFile, json_encode($appComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function runRefactor()
    {
        $userRectorConfig = null;

        foreach (['rector.php', 'rector.dist.php'] as $rectorFile) {
            if (file_exists(getcwd() . '/' . $rectorFile)) {
                $userRectorConfig = $rectorFile;
                break;
            }
        }

        if (!Core::get('refactor') && !$userRectorConfig) {
            $this->writeln('<comment>No `refactor` section found in alchemy.yml. Add one to use Rector.</comment>');
            return 0;
        }

        if (!file_exists(getcwd() . '/vendor/bin/rector')) {
            $this->writeln("<info>Setting up refactoring with rector...</info>\n");

            if (!sprout()->composer()->install('rector/rector --dev')->isSuccessful()) {
                $this->writeln('<error>Couldn\'t install rector. Check your connection and try again.</error>');
                return 1;
            }

            $this->writeln('<info>Rector installed successfully!</info>');
        }

        // a pinned config file or a project with its own rector config and
        // no refactor section runs on its own setup — same contract as analyse
        if ($pinned = $this->pinnedConfig('refactor')) {
            $this->writeln("<comment>Using your existing $pinned...</comment>\n");
            $refactorConfigPath = getcwd() . '/' . $pinned;
        } elseif (!Core::get('refactor') && $userRectorConfig) {
            $this->writeln("<comment>Using your existing $userRectorConfig (alchemy.yml has no refactor section)...</comment>\n");
            $refactorConfigPath = getcwd() . '/' . $userRectorConfig;
        } else {
            Core::generateRefactorFiles();
            $refactorConfigPath = getcwd() . '/.alchemy/.rector.dist.php';
        }

        $check = $this->checkMode();

        $this->writeln($check ? "<comment>Checking for pending refactors...</comment>\n" : "<comment>Running refactors...</comment>\n");

        $refactorProcess = sprout()
            ->process(getcwd() . '/vendor/bin/rector process --config=' . $refactorConfigPath . ($check ? ' --dry-run' : ''))
            ->setTimeout(null)
            ->run(function ($type, $line): void {
                $this->write($line);
            });

        if (strpos($refactorConfigPath, '/.alchemy/') !== false) {
            $this->discardGeneratedConfig('.rector.dist.php');
        }

        if ($refactorProcess !== 0) {
            $this->writeln($check
                ? '<error>Pending refactors found. Run `composer run refactor` locally to apply them.</error>'
                : '<error>Refactoring failed. Check your code and try again.</error>');
            return 1;
        }

        return 0;
    }

    protected function runAnalyser()
    {
        $userPhpstanConfig = null;

        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $phpstanFile) {
            if (file_exists(getcwd() . '/' . $phpstanFile)) {
                $userPhpstanConfig = $phpstanFile;
                break;
            }
        }

        if (!Core::get('analyse') && !$userPhpstanConfig) {
            $this->writeln('<comment>No `analyse` section found in alchemy.yml. Add one to use PHPStan.</comment>');
            return 0;
        }

        if (!file_exists(getcwd() . '/vendor/bin/phpstan')) {
            $this->writeln("<info>Setting up static analysis with phpstan...</info>\n");

            if (!sprout()->composer()->install('phpstan/phpstan --dev')->isSuccessful()) {
                $this->writeln('<error>Couldn\'t install phpstan. Check your connection and try again.</error>');
                return 1;
            }

            $this->writeln('<info>PHPStan installed successfully!</info>');
        }

        // a pinned config file or a project with its own phpstan config and
        // no analyse section runs on its own setup, baseline and all
        if ($pinned = $this->pinnedConfig('analyse')) {
            $this->writeln("<comment>Using your existing $pinned...</comment>\n");
            $analyseConfigPath = getcwd() . '/' . $pinned;
        } elseif (!Core::get('analyse') && $userPhpstanConfig) {
            $this->writeln("<comment>Using your existing $userPhpstanConfig (alchemy.yml has no analyse section)...</comment>\n");
            $analyseConfigPath = getcwd() . '/' . $userPhpstanConfig;
        } else {
            $this->ensurePestPhpstanPlugin();
            $this->ensureLarastan();
            Core::generateAnalyseFiles();
            $analyseConfigPath = getcwd() . '/.alchemy/.phpstan.dist.neon';
        }

        $this->writeln("<comment>Analysing code...</comment>\n");

        $analyseProcess = sprout()
            ->process(getcwd() . "/vendor/bin/phpstan analyse --configuration=$analyseConfigPath --no-progress --ansi")
            ->setTimeout(null)
            ->run(function ($type, $line): void {
                $this->write($line);
            });

        if (strpos($analyseConfigPath, '/.alchemy/') !== false) {
            $this->discardGeneratedConfig('.phpstan.dist.neon');
        }

        if ($analyseProcess !== 0) {
            $this->writeln('<error>Static analysis found issues. Fix them and run `composer run analyse` again.</error>');
            return 1;
        }

        return 0;
    }

    /**
     * A pest project analysing its own tests needs phpstan taught pest's DSL
     * (it(), expect(), $this binding) — pest 5 ships a first-party plugin
     */
    protected function ensurePestPhpstanPlugin()
    {
        if (!file_exists(getcwd() . '/vendor/bin/pest') || is_dir(getcwd() . '/vendor/pestphp/pest-plugin-phpstan')) {
            return;
        }

        $analyseConfig = is_array(Core::get('analyse')) ? Core::get('analyse') : [];
        $testsConfig = is_array(Core::get('tests')) ? Core::get('tests') : [];

        $analysedPaths = (array) ($analyseConfig['paths'] ?? Core::get('app') ?? []);
        $testPaths = (array) ($testsConfig['paths'] ?? ['tests']);
        $coversTests = false;

        foreach ($analysedPaths as $analysedPath) {
            foreach ($testPaths as $testPath) {
                if ($analysedPath === $testPath || strpos("$testPath/", "$analysedPath/") === 0 || strpos("$analysedPath/", "$testPath/") === 0) {
                    $coversTests = true;
                }
            }
        }

        if (!$coversTests) {
            return;
        }

        $this->writeln("<info>Your analyse paths cover your tests — adding pest's phpstan plugin so phpstan understands pest syntax...</info>\n");

        if (!sprout()->composer()->install('pestphp/pest-plugin-phpstan --dev')->isSuccessful()) {
            $this->writeln("<comment>Couldn't install pestphp/pest-plugin-phpstan (it needs Pest 5 on PHP 8.4). Continuing without it.</comment>\n");
        }
    }

    /**
     * Raw phpstan on a Laravel app drowns in facade/magic false positives —
     * larastan is how Laravel is actually analysed, so wire it in
     */
    protected function ensureLarastan()
    {
        if (is_dir(getcwd() . '/vendor/larastan/larastan')) {
            return;
        }

        $composerJson = json_decode((string) @file_get_contents(getcwd() . '/composer.json'), true) ?? [];
        $deps = array_merge($composerJson['require'] ?? [], $composerJson['require-dev'] ?? []);

        if (!isset($deps['laravel/framework'])) {
            return;
        }

        $this->writeln("<info>Laravel project detected — adding larastan so phpstan understands facades, Eloquent and container magic...</info>\n");

        if (!sprout()->composer()->install('larastan/larastan --dev')->isSuccessful()) {
            $this->writeln("<comment>Couldn't install larastan/larastan. Continuing with plain phpstan.</comment>\n");
        }
    }

    protected function runLinter()
    {
        $check = $this->checkMode();

        // a pinned config file or a project with its own linter config and no
        // lint section keeps its setup untouched — the file names the tool
        if ($userConfig = ($this->pinnedConfig('lint') ?? $this->userEngineConfig('lint'))) {
            $provider = basename($userConfig) === 'pint.json' ? 'pint' : 'phpcsfixer';

            if ($this->ensureLinterInstalled($provider) !== 0) {
                return 1;
            }

            $this->writeln("<comment>Using your existing $userConfig...</comment>\n");

            $lintCommand = $provider === 'pint'
                ? getcwd() . "/vendor/bin/pint --config $userConfig" . ($check ? ' --test' : '')
                : getcwd() . "/vendor/bin/php-cs-fixer fix --config=$userConfig" . ($check ? ' --dry-run --diff' : '');
            $lintCommand .= $this->forwardedFlags();

            $lintProcess = sprout()
                ->process($lintCommand)
                ->setTimeout(null)
                ->run(function ($type, $line): void {
                    $this->write($line);
                });

            if ($lintProcess !== 0) {
                $this->writeln($check
                    ? '<error>Style violations found. Run `composer run fmt` locally to fix them.</error>'
                    : '<error>Linting failed. Check your code and try again.</error>');
                return 1;
            }

            return 0;
        }

        $provider = Core::lintProvider();

        if ($provider === null) {
            $lintConfig = is_array(Core::get('lint')) ? Core::get('lint') : [];
            $this->writeln('<error>Unknown lint provider "' . ($lintConfig['provider'] ?? '') . '". Supported: phpcsfixer, pint.</error>');
            return 1;
        }

        if ($this->ensureLinterInstalled($provider) !== 0) {
            return 1;
        }

        $lintConfig = is_array(Core::get('lint')) ? Core::get('lint') : [];

        $this->writeln($check ? "<comment>Checking code style...</comment>\n" : "<comment>Running linter...</comment>\n");

        if ($provider === 'pint') {
            Core::generatePintConfig();

            $lintFlags = ' --config ' . getcwd() . '/.alchemy/pint.json --cache-file ' . getcwd() . '/.alchemy/.pint.cache';
            $lintFlags .= ($lintConfig['parallel'] ?? false) ? ' --parallel' : '';
            $lintFlags .= $check ? ' --test' : '';
            $lintFlags .= $this->forwardedFlags();

            // pint carries paths as arguments, not config
            $lintCommand = getcwd() . '/vendor/bin/pint'
                . ($this->lintPaths($lintConfig) ? ' ' . implode(' ', $this->lintPaths($lintConfig)) : '')
                . $lintFlags;
        } else {
            Core::generateLintFiles();

            $risky = ($lintConfig['risky'] ?? true) ? ' --allow-risky=yes' : '';
            $lintFlags = $risky . ($check ? ' --dry-run --diff' : '') . $this->forwardedFlags();
            $lintCommand = getcwd() . '/vendor/bin/php-cs-fixer fix --config=' . getcwd() . "/.alchemy/.php_cs.dist.php$lintFlags";
        }

        $lintProcess = sprout()
            ->process($lintCommand)
            ->setTimeout(null)
            ->run(function ($type, $line): void {
                $this->write($line);
            });

        $this->discardGeneratedConfig($provider === 'pint' ? 'pint.json' : '.php_cs.dist.php');

        if ($lintProcess !== 0) {
            $this->writeln($check
                ? '<error>Style violations found. Run `composer run lint` locally to fix them.</error>'
                : '<error>Linting failed. Check your code and try again.</error>');
            return 1;
        }

        return 0;
    }

    /**
     * Same lint scope for both providers: app paths, plus test paths unless
     * ignore_tests. Empty means the project root (pint skips vendor itself).
     */
    protected function lintPaths(array $lintConfig): array
    {
        $appPaths = (array) (Core::get('app') ?? []);

        if (!$appPaths) {
            return [];
        }

        if (empty($lintConfig['ignore_tests'])) {
            $testsConfig = is_array(Core::get('tests')) ? Core::get('tests') : [];
            $appPaths = array_merge($appPaths, (array) ($testsConfig['paths'] ?? []));
        }

        return array_values(array_unique($appPaths));
    }

    /**
     * `composer run test -- --flags=coverage` forwards any engine flag.
     * Sprout hands a comma-separated value back as an array already, so
     * accept both shapes.
     */
    protected function forwardedFlags(): string
    {
        $flagsOption = $this->option('flags');

        if (!$flagsOption || $flagsOption === true) {
            return '';
        }

        $flagList = is_array($flagsOption) ? $flagsOption : explode(',', (string) $flagsOption);

        return ' --' . implode(' --', array_map(fn ($flag) => ltrim((string) $flag, '-'), $flagList));
    }

    protected function ensureLinterInstalled(string $provider): int
    {
        [$binary, $package, $label] = $provider === 'pint'
            ? ['pint', 'laravel/pint', 'Pint']
            : ['php-cs-fixer', 'friendsofphp/php-cs-fixer', 'PHP-CS-Fixer'];

        if (file_exists(getcwd() . "/vendor/bin/$binary")) {
            return 0;
        }

        $this->writeln("<info>Setting up linting with $binary...</info>\n");

        if (!sprout()->composer()->install("$package --dev")->isSuccessful()) {
            $this->writeln("<error>Couldn't install $label. Check your connection and try again.</error>");
            return 1;
        }

        $this->writeln('<info>Linter installed successfully!</info>');

        return 0;
    }

    protected const CI_MARKER = "# Generated by Leaf Alchemy from alchemy.yml — edits will be overwritten on the next alchemy run.\n# Remove this header to take ownership of this file.";

    /**
     * A CI file is alchemy's to regenerate if it doesn't exist yet or still
     * carries a generated-by header; without one it belongs to the user
     */
    protected function ciFileIsAlchemyOwned(string $file): bool
    {
        if (!file_exists($file)) {
            return true;
        }

        $contents = \Leaf\FS\File::read($file);

        return strpos($contents, '# Generated by Leaf Alchemy') === 0
            || strpos($contents, '# Generated by Alchemy') === 0;
    }

    protected function generateActions()
    {
        $config = Core::get('actions') ?? [];
        $providers = (array) ($config['provider'] ?? 'github');
        $status = 0;

        foreach ($providers as $provider) {
            if ($provider === 'github') {
                $status = $this->generateGithubActions($config) ?: $status;
            } elseif ($provider === 'gitlab') {
                $status = $this->generateGitlabCi($config) ?: $status;
            } elseif ($provider === 'circleci') {
                $status = $this->generateCircleCi($config) ?: $status;
            } else {
                $this->writeln("<error>Unknown CI provider \"$provider\". Supported: github, gitlab, circleci.</error>");
                $status = 1;
            }
        }

        return $status;
    }

    protected function generateGithubActions($config)
    {
        $actionToRun = array_unique(array_map(
            fn ($action) => $action === 'test' ? 'tests' : $action,
            $config['run'] ?? []
        ));

        $validActions = array_map(
            fn ($stub) => basename($stub, '.yml'),
            glob(dirname(__DIR__) . '/setup/workflows/*.yml') ?: []
        );

        if ($unknownActions = array_diff($actionToRun, $validActions)) {
            $this->writeln(
                '<error>Unknown action(s) in actions.run: ' . implode(', ', $unknownActions) .
                    '. Valid actions are: ' . implode(', ', $validActions) . '.</error>'
            );

            return 1;
        }

        \Leaf\FS\Directory::create(getcwd() . '/.github');

        foreach ($actionToRun as $action) {
            $actionFile = getcwd() . "/.github/workflows/$action.yml";

            $phpVersions = $config['php']['versions'] ?? ['8.3'];
            $phpExtensions = $config['php']['extensions'] ?? 'json, zip';

            $os = $config['os'] ?? ['ubuntu-latest'];
            $events = $config['events'] ?? $config['event'] ?? ['push'];
            $failFast = $config['fail-fast'] ?? true;
            $lintAutofix = is_array(Core::get('lint')) ? (Core::get('lint')['autofix'] ?? false) : false;

            $actionsToWrite = [];
            $testsConfig = is_array(Core::get('tests')) ? Core::get('tests') : [];
            $database = $testsConfig['database'] ?? false;

            $actionsCoverage = (
                $config['tests']['coverage']['actions'] ??
                $testsConfig['coverage']['actions'] ??
                true
            ) ? 'xdebug' : 'none';

            $coverageFlags = $actionsCoverage !== 'none' ? ' -- --flags=coverage' : '';

            if ($database) {
                $dbName = $database['connection']['name'] ?? 'test';
                $dbUser = $database['connection']['username'] ?? 'root';
                $dbPassword = $database['connection']['password'] ?? '';
                $dbPort = $database['connection']['port'] ?? 3306;

                if ($database['type'] === 'mysql') {
                    $actionsToWrite[] = "\n
      - name: Boot MySQL
        run: sudo systemctl start mysql.service";

                    $actionsToWrite[] = "
      - name: Initialize database
        run: |
          mysql -e 'CREATE DATABASE $dbName;' \
          -u$dbUser -p$dbPassword -P$dbPort";
                } elseif ($database['type'] === 'pgsql') {
                    $actionsToWrite[] = "\n
      - name: Initialize Database
        uses: ikalnytskyi/action-setup-postgres@v8
        with:
          username: $dbUser
          password: $dbPassword
          database: $dbName
          port: $dbPort
          ssl: on
        id: postgres";
                }
            }

            // regenerate alchemy-owned workflows so action pins and stub fixes
            // propagate; a file without the marker header belongs to the user
            if ($this->ciFileIsAlchemyOwned($actionFile)) {
                $this->writeln("<info>Writing GitHub action $action.yml...</info>");

                $actionStub = \Leaf\FS\File::read(dirname(__DIR__) . "/setup/workflows/$action.yml");

                $lintRun = $lintAutofix ? 'composer run fmt' : 'composer run lint -- --check';
                $lintSteps = $lintAutofix ? "\n
      - name: Commit style fixes
        uses: stefanzweifel/git-auto-commit-action@v7
        with:
          commit_message: 'chore: fix styling'" : '';

                $actionStub = str_replace(
                    ['ACTIONS.PHP.VERSIONS', 'ACTIONS.PHP.VERSION', 'ACTIONS.PHP.EXTENSIONS', 'ACTIONS.OS', 'ACTIONS.EVENTS', 'ACTIONS.FAILFAST', 'ACTIONS.PHP.COVERAGE', 'ACTIONS.PHP.ACTIONS', 'ACTIONS.STEPS.COVERAGE', 'ACTIONS.LINT.RUN', 'ACTIONS.LINT.STEPS'],
                    [Core::unJsonify($phpVersions, 0), end($phpVersions), $phpExtensions, Core::unJsonify($os, 0), Core::unJsonify($events, 0), $failFast ? 'true' : 'false', $actionsCoverage, implode("\n", $actionsToWrite), $coverageFlags, $lintRun, $lintSteps],
                    $actionStub
                );

                \Leaf\FS\File::create($actionFile, $actionStub, [
                    'recursive' => true,
                ]);
            }
        }

        return 0;
    }

    protected function generateGitlabCi($config)
    {
        $ciFile = getcwd() . '/.gitlab-ci.yml';

        if (!$this->ciFileIsAlchemyOwned($ciFile)) {
            $this->writeln('<comment>.gitlab-ci.yml is user-managed, skipping.</comment>');
            return 0;
        }

        $jobs = $config['run'] ?? [];
        $phpVersions = $config['php']['versions'] ?? ['8.3'];
        $latestPhp = end($phpVersions);
        $events = $config['events'] ?? $config['event'] ?? ['push'];

        $this->writeln('<info>Writing .gitlab-ci.yml...</info>');

        $yml = static::CI_MARKER . "\n\n";

        if (in_array('pull_request', $events) && !in_array('push', $events)) {
            $yml .= "workflow:\n  rules:\n    - if: \$CI_PIPELINE_SOURCE == 'merge_request_event'\n\n";
        }

        $yml .= "stages:\n  - qa\n  - test\n\n";
        $yml .= ".php-job:\n";
        $yml .= "  before_script:\n";
        $yml .= "    - apt-get update -yqq && apt-get install -yqq git unzip libzip-dev\n";
        $yml .= "    - docker-php-ext-install zip > /dev/null\n";
        $yml .= "    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer\n";
        $yml .= "    - composer update --no-interaction --no-progress\n";
        $yml .= "  cache:\n    key: composer-\$CI_JOB_NAME\n    paths:\n      - vendor/\n\n";

        foreach ($jobs as $job) {
            if ($job === 'tests' || $job === 'test') {
                $versionsList = "'" . implode("', '", $phpVersions) . "'";
                $yml .= "test:\n  extends: .php-job\n  stage: test\n  image: php:\${PHP_VERSION}-cli\n";
                $yml .= "  parallel:\n    matrix:\n      - PHP_VERSION: [$versionsList]\n";
                $yml .= "  script:\n    - composer run test\n\n";
            } elseif ($job === 'lint') {
                $yml .= "lint:\n  extends: .php-job\n  stage: qa\n  image: php:$latestPhp-cli\n  script:\n    - composer run lint -- --check\n\n";
            } elseif ($job === 'refactor') {
                $yml .= "refactor:\n  extends: .php-job\n  stage: qa\n  image: php:$latestPhp-cli\n  script:\n    - composer run refactor -- --check\n\n";
            } elseif ($job === 'analyse') {
                $yml .= "analyse:\n  extends: .php-job\n  stage: qa\n  image: php:$latestPhp-cli\n  script:\n    - composer run analyse\n\n";
            }
        }

        \Leaf\FS\File::create($ciFile, rtrim($yml) . "\n");

        return 0;
    }

    protected function generateCircleCi($config)
    {
        $ciFile = getcwd() . '/.circleci/config.yml';

        if (!$this->ciFileIsAlchemyOwned($ciFile)) {
            $this->writeln('<comment>.circleci/config.yml is user-managed, skipping.</comment>');
            return 0;
        }

        $jobs = $config['run'] ?? [];
        $phpVersions = $config['php']['versions'] ?? ['8.3'];
        $latestPhp = end($phpVersions);

        $this->writeln('<info>Writing .circleci/config.yml...</info>');

        $yml = static::CI_MARKER . "\nversion: 2.1\n\njobs:\n";
        $workflowJobs = '';

        foreach ($jobs as $job) {
            if ($job === 'tests' || $job === 'test') {
                $versionsList = "'" . implode("', '", $phpVersions) . "'";
                $yml .= "  test:\n    parameters:\n      php:\n        type: string\n";
                $yml .= "    docker:\n      - image: cimg/php:<< parameters.php >>\n";
                $yml .= "    steps:\n      - checkout\n      - run: composer update --no-interaction --no-progress\n      - run: composer run test\n";
                $workflowJobs .= "      - test:\n          matrix:\n            parameters:\n              php: [$versionsList]\n";
            } elseif ($job === 'lint') {
                $yml .= "  lint:\n    docker:\n      - image: cimg/php:$latestPhp\n";
                $yml .= "    steps:\n      - checkout\n      - run: composer update --no-interaction --no-progress\n      - run: composer run lint -- --check\n";
                $workflowJobs .= "      - lint\n";
            } elseif ($job === 'refactor') {
                $yml .= "  refactor:\n    docker:\n      - image: cimg/php:$latestPhp\n";
                $yml .= "    steps:\n      - checkout\n      - run: composer update --no-interaction --no-progress\n      - run: composer run refactor -- --check\n";
                $workflowJobs .= "      - refactor\n";
            } elseif ($job === 'analyse') {
                $yml .= "  analyse:\n    docker:\n      - image: cimg/php:$latestPhp\n";
                $yml .= "    steps:\n      - checkout\n      - run: composer update --no-interaction --no-progress\n      - run: composer run analyse\n";
                $workflowJobs .= "      - analyse\n";
            }
        }

        $yml .= "\nworkflows:\n  qa:\n    jobs:\n$workflowJobs";

        \Leaf\FS\Directory::create(getcwd() . '/.circleci');
        \Leaf\FS\File::create($ciFile, rtrim($yml) . "\n");

        return 0;
    }
}
