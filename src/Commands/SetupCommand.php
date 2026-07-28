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
        {--flags? : Add flags to the command being run separated by commas}';
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
            $this->runTests();
            $this->runLinter();

            // rector rewrites code and phpstan needs a chosen level,
            // so they only join the pipeline when configured
            if (Core::get('refactor')) {
                $this->runRefactor();
            }

            if (Core::get('analyse')) {
                $this->runAnalyser();
            }

            $this->generateActions();
        }

        $this->writeln('<info>Alchemy setup successfully.</info>');

        return 0;
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

        \Leaf\FS\Directory::create(getcwd() . '/.alchemy');
    }

    /**
     * Alchemy never migrates a tool you didn't hand it: when your alchemy.yml
     * doesn't configure a tool but the project has its own config file for it,
     * that file is used as-is.
     */
    protected function userEngineConfig(string $tool): ?string
    {
        if ($tool === 'lint' && empty($this->projectConfig['lint'])) {
            foreach (['.php-cs-fixer.php', '.php-cs-fixer.dist.php'] as $file) {
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

    protected function checkMode(): bool
    {
        if ($this->modeOverride !== null) {
            return $this->modeOverride === 'check';
        }

        return (bool) $this->option('check');
    }

    protected function runTests()
    {
        $config = Core::get('tests');
        $userConfig = $this->userEngineConfig('tests');

        $engine = $config['engine'] ?? 'pest';
        $parallel = $config['parallel'] ?? false;

        // a project with its own phpunit.xml and no tests section runs on its own config
        if ($userConfig) {
            $engine = file_exists(getcwd() . '/vendor/bin/phpunit') && !file_exists(getcwd() . '/vendor/bin/pest') ? 'phpunit' : 'pest';
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
            $this->writeln('<comment>  > Using your existing phpunit.xml (alchemy.yml has no tests section)...</comment>');

            $userFlags = $engine === 'pest' ? '--colors=always' : '';
            $userFlags .= $this->option('flags')
                ? (' --' . implode(' --', explode(',', $this->option('flags'))))
                : '';

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
        $flags .= $this->option('flags')
            ? (' --' . implode(' --', explode(',', $this->option('flags'))))
            : '';

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

        if ($testProcess !== 0) {
            $this->writeln('<error>Tests failed. Check your code and try again.</error>');
            return 1;
        }

        return 0;
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
        if (!Core::get('refactor')) {
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

        Core::generateRefactorFiles();

        $check = $this->checkMode();

        $this->writeln($check ? "<comment>Checking for pending refactors...</comment>\n" : "<comment>Running refactors...</comment>\n");

        $refactorProcess = sprout()
            ->process(getcwd() . '/vendor/bin/rector process --config=' . getcwd() . '/.alchemy/.rector.dist.php' . ($check ? ' --dry-run' : ''))
            ->setTimeout(null)
            ->run(function ($type, $line): void {
                $this->write($line);
            });

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

        // a project with its own phpstan config and no analyse section
        // runs on its own setup, baseline and all
        if (!Core::get('analyse') && $userPhpstanConfig) {
            $this->writeln("<comment>Using your existing $userPhpstanConfig (alchemy.yml has no analyse section)...</comment>\n");
            $analyseConfigPath = getcwd() . '/' . $userPhpstanConfig;
        } else {
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

        if ($analyseProcess !== 0) {
            $this->writeln('<error>Static analysis found issues. Fix them and run `composer run analyse` again.</error>');
            return 1;
        }

        return 0;
    }

    protected function runLinter()
    {
        if (!file_exists(getcwd() . '/vendor/bin/php-cs-fixer')) {
            $this->writeln("<info>Setting up linting with php-cs-fixer...</info>\n");

            if (!sprout()->composer()->install('friendsofphp/php-cs-fixer --dev')->isSuccessful()) {
                $this->writeln('<error>Couldn\'t install PHP-CS-Fixer. Check your connection and try again.</error>');
                return 1;
            }

            $this->writeln('<info>Linter installed successfully!</info>');
        }

        $check = $this->checkMode();

        // a project with its own fixer config and no lint section keeps its setup untouched
        if ($userConfig = $this->userEngineConfig('lint')) {
            $this->writeln("<comment>Using your existing $userConfig (alchemy.yml has no lint section)...</comment>\n");

            $lintProcess = sprout()
                ->process(getcwd() . "/vendor/bin/php-cs-fixer fix --config=$userConfig" . ($check ? ' --dry-run --diff' : ''))
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

        Core::generateLintFiles();

        $risky = (Core::get('lint')['risky'] ?? true) ? ' --allow-risky=yes' : '';
        $lintFlags = $risky . ($check ? ' --dry-run --diff' : '');

        $this->writeln($check ? "<comment>Checking code style...</comment>\n" : "<comment>Running linter...</comment>\n");

        $lintProcess = sprout()
            ->process(getcwd() . '/vendor/bin/php-cs-fixer fix --config=' . getcwd() . "/.alchemy/.php_cs.dist.php$lintFlags")
            ->setTimeout(null)
            ->run(function ($type, $line): void {
                $this->write($line);
            });

        if ($lintProcess !== 0) {
            $this->writeln($check
                ? '<error>Style violations found. Run `composer run lint` locally to fix them.</error>'
                : '<error>Linting failed. Check your code and try again.</error>');
            return 1;
        }

        return 0;
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
        $actionToRun = $config['run'] ?? [];

        \Leaf\FS\Directory::create(getcwd() . '/.github');

        foreach ($actionToRun as $action) {
            $actionFile = getcwd() . "/.github/workflows/$action.yml";

            $phpVersions = $config['php']['versions'] ?? ['8.3'];
            $phpExtensions = $config['php']['extensions'] ?? 'json, zip';

            $os = $config['os'] ?? ['ubuntu-latest'];
            $events = $config['events'] ?? $config['event'] ?? ['push'];
            $failFast = $config['fail-fast'] ?? true;
            $lintAutofix = Core::get('lint')['autofix'] ?? false;

            $actionsToWrite = [];
            $database = Core::get('tests')['database'] ?? false;
            $actionsCoverage = ($config['tests']['coverage']['actions'] ?? true) ? 'xdebug' : 'none';
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
        uses: ikalnytskyi/action-setup-postgres@v6
        with:
          username: $dbUser
          password: $dbPassword
          database: $dbName
          port: $dbPort
          ssl: on
        id: postgres";
                }
            }

            if (!file_exists($actionFile)) {
                $this->writeln("<info>Writing GitHub action $action.yml...</info>");

                $actionStub = \Leaf\FS\File::read(dirname(__DIR__) . "/setup/workflows/$action.yml");

                $lintRun = $lintAutofix ? 'composer run fmt' : 'composer run lint -- --check';
                $lintSteps = $lintAutofix ? "\n
      - name: Commit style fixes
        uses: stefanzweifel/git-auto-commit-action@v5
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

        if (file_exists($ciFile)) {
            $this->writeln('<comment>.gitlab-ci.yml already exists, skipping.</comment>');
            return 0;
        }

        $jobs = $config['run'] ?? [];
        $phpVersions = $config['php']['versions'] ?? ['8.3'];
        $latestPhp = end($phpVersions);
        $events = $config['events'] ?? $config['event'] ?? ['push'];

        $this->writeln('<info>Writing .gitlab-ci.yml...</info>');

        $yml = "# Generated by Alchemy\n\n";

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

        if (file_exists($ciFile)) {
            $this->writeln('<comment>.circleci/config.yml already exists, skipping.</comment>');
            return 0;
        }

        $jobs = $config['run'] ?? [];
        $phpVersions = $config['php']['versions'] ?? ['8.3'];
        $latestPhp = end($phpVersions);

        $this->writeln('<info>Writing .circleci/config.yml...</info>');

        $yml = "# Generated by Alchemy\nversion: 2.1\n\njobs:\n";
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
