<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Alchemy\Core;
use Symfony\Component\Yaml\Yaml;

class SwitchCommand extends SetupCommand
{
    protected $signature = 'switch
        {target : What to switch to: github, gitlab, circleci, pest or phpunit}
        {--clean? : Delete the previous CI provider\'s generated files}';
    protected $description = 'Switch CI provider or test engine — config is regenerated from the same alchemy.yml';
    protected $help = 'Updates alchemy.yml and regenerates everything for the new target. Switching CI providers regenerates pipelines; switching test engines updates your engine and leaves installs to the next test run.';

    protected const CI_PROVIDERS = ['github', 'gitlab', 'circleci'];
    protected const TEST_ENGINES = ['pest', 'phpunit'];

    protected const PROVIDER_FILES = [
        'github' => ['.github/workflows/lint.yml', '.github/workflows/tests.yml', '.github/workflows/refactor.yml', '.github/workflows/analyse.yml'],
        'gitlab' => ['.gitlab-ci.yml'],
        'circleci' => ['.circleci/config.yml'],
    ];

    protected function handle(): int
    {
        $configFile = getcwd() . '/alchemy.yml';

        if (!file_exists($configFile)) {
            $this->writeln('<error>No alchemy.yml found. Run `alchemy init` first.</error>');
            return 1;
        }

        $target = $this->argument('target');
        $config = Yaml::parseFile($configFile);

        if (in_array($target, static::CI_PROVIDERS)) {
            return $this->switchCiProvider($target, $config, $configFile);
        }

        if (in_array($target, static::TEST_ENGINES)) {
            return $this->switchTestEngine($target, $config, $configFile);
        }

        $this->writeln("<error>Unknown target \"$target\". Supported: " . implode(', ', array_merge(static::CI_PROVIDERS, static::TEST_ENGINES)) . '.</error>');

        return 1;
    }

    protected function switchCiProvider(string $target, array $config, string $configFile): int
    {
        $previousProviders = (array) ($config['actions']['provider'] ?? 'github');

        if ($previousProviders === [$target]) {
            $this->writeln("<comment>Already on $target — nothing to switch.</comment>");
            return 0;
        }

        $config['actions']['provider'] = $target;
        \Leaf\FS\File::write($configFile, Yaml::dump($config, 6, 2));
        $this->writeln("<info>alchemy.yml updated: CI provider is now $target.</info>");
        $this->writeln('<comment>Note: alchemy.yml was rewritten, so any comments in it were dropped.</comment>');

        Core::set($config);
        Core::ensureAlchemyDir();
        $this->generateActions();

        $leftoverFiles = [];

        foreach ($previousProviders as $previousProvider) {
            if ($previousProvider === $target) {
                continue;
            }

            foreach (static::PROVIDER_FILES[$previousProvider] ?? [] as $file) {
                if (file_exists(getcwd() . "/$file")) {
                    if ($this->option('clean')) {
                        \Leaf\FS\File::delete(getcwd() . "/$file");
                        $this->writeln("<info>Removed $file</info>");
                    } else {
                        $leftoverFiles[] = $file;
                    }
                }
            }
        }

        if ($leftoverFiles) {
            $this->writeln('<comment>Old provider files left in place (re-run with --clean to remove): ' . implode(', ', $leftoverFiles) . '</comment>');
        }

        return 0;
    }

    protected function switchTestEngine(string $target, array $config, string $configFile): int
    {
        $previousEngine = $config['tests']['engine'] ?? 'pest';

        if ($previousEngine === $target) {
            $this->writeln("<comment>Already using $target — nothing to switch.</comment>");
            return 0;
        }

        $config['tests']['engine'] = $target;
        \Leaf\FS\File::write($configFile, Yaml::dump($config, 6, 2));

        $this->writeln("<info>alchemy.yml updated: test engine is now $target.</info>");
        $this->writeln('<comment>Note: alchemy.yml was rewritten, so any comments in it were dropped.</comment>');
        $this->writeln("<comment>$target will be installed on your next `composer run test`. You can remove the old engine with `composer remove " . ($previousEngine === 'pest' ? 'pestphp/pest' : 'phpunit/phpunit') . '`.</comment>');

        if ($previousEngine === 'pest' && $target === 'phpunit') {
            $this->writeln('<comment>Heads up: pest-style tests (test()/expect()) won\'t run on plain phpunit — migrate them to PHPUnit classes first.</comment>');
        }

        return 0;
    }
}
