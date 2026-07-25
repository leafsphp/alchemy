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
        {--gh|actions? : Generate GitHub actions}
        {--c|check? : Check code style without fixing anything (used in CI)}
        {--f|force? : Replace test or tests folder if it exists}
        {--flags? : Add flags to the command being run separated by commas}';
    protected $description = 'Setup work environment based on Alchemy configuration';
    protected $help = 'This command will help you setup your work environment based on the alchemy.yml configuration file.';

    /**
     * Execute the command.
     * @return int
     */
    protected function handle(): int
    {
        $configFile = getcwd() . '/alchemy.yml';

        if (file_exists($configFile)) {
            Core::set(Yaml::parseFile($configFile));
        } else {
            $this->writeln('<comment>No alchemy.yml found, using default configuration. Run `alchemy config:install` to customize.</comment>');
            Core::set(Yaml::parseFile(dirname(__DIR__) . '/setup/alchemy.yml'));
        }

        \Leaf\FS\Directory::create(getcwd() . '/.alchemy');

        if ($this->option('test')) {
            set_time_limit(0);
            return $this->runTests();
        }

        if ($this->option('lint')) {
            return $this->runLinter();
        }

        if ($this->option('actions')) {
            return $this->generateActions();
        }

        if (!$this->option('test') && !$this->option('lint') && !$this->option('actions')) {
            $this->runTests();
            $this->runLinter();
            $this->generateActions();
        }

        $this->writeln('<info>Alchemy setup successfully.</info>');

        return 0;
    }

    protected function runTests()
    {
        $config = Core::get('tests');

        $engine = $config['engine'] ?? 'pest';
        $parallel = $config['parallel'] ?? false;
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

        // never clobber a hand-written phpunit.xml — park it and restore after the run
        $userPhpunitConfig = file_exists(getcwd() . '/phpunit.xml');

        if ($userPhpunitConfig) {
            \Leaf\FS\File::move(getcwd() . '/phpunit.xml', getcwd() . '/.alchemy/phpunit.xml.user');
        }

        Core::generateTestFiles();

        $this->writeln("<comment>  > Using $engine for tests ...</comment>");

        $binary = $engine;
        $flags = $engine === 'pest' ? '--colors=always' : '';
        $flags .= $this->option('flags')
            ? (' --' . implode(' --', explode(',', $this->option('flags'))))
            : '';

        if ($parallel) {
            $this->writeln("<info>  > Running tests in parallel...</info>");

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

        try {
            $testProcess = sprout()
                ->process(getcwd() . "/vendor/bin/$binary $flags")
                ->run(function ($type, $line): void {
                    $this->write($line);
                });
        } finally {
            \Leaf\FS\File::delete(getcwd() . '/phpunit.xml');

            if ($userPhpunitConfig) {
                \Leaf\FS\File::move(getcwd() . '/.alchemy/phpunit.xml.user', getcwd() . '/phpunit.xml');
            }

            if (file_exists(getcwd() . '/.phpunit.result.cache')) {
                \Leaf\FS\File::move(getcwd() . '/.phpunit.result.cache', getcwd() . '/.alchemy/.phpunit.result.cache');
            }
        }

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

        Core::generateLintFiles();

        $check = $this->option('check');
        $risky = (Core::get('lint')['risky'] ?? true) ? ' --allow-risky=yes' : '';
        $lintFlags = $risky . ($check ? ' --dry-run --diff' : '');

        $this->writeln($check ? "<comment>Checking code style...</comment>\n" : "<comment>Running linter...</comment>\n");

        try {
            $lintProcess = sprout()
                ->process(getcwd() . "/vendor/bin/php-cs-fixer fix --config=.php_cs.dist.php$lintFlags")
                ->run(function ($type, $line): void {
                    $this->write($line);
                });
        } finally {
            \Leaf\FS\File::delete(getcwd() . '/.php_cs.dist.php');

            if (file_exists(getcwd() . '/.php-cs-fixer.cache')) {
                \Leaf\FS\File::move(getcwd() . '/.php-cs-fixer.cache', getcwd() . '/.alchemy/.php-cs-fixer.cache');
            }
        }

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
        $config = Core::get('actions');
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
                } else if ($database['type'] === 'pgsql') {
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

                $lintRun = $lintAutofix ? 'composer run lint' : 'composer run lint -- --check';
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
}
