<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Sprout\Command;

class InstallCommand extends Command
{
    protected $signature = 'config:install
        {--f|force? : Replace alchemy files if they already exist}';
    protected $description = 'Generate base alchemy files';
    protected $help = 'This command will help you generate the base alchemy files.';

    /**
     * Execute the command.
     * @return int
     */
    protected function handle(): int
    {
        $appAlchemyFile = getcwd() . '/alchemy.yml';

        if (!file_exists($appAlchemyFile) || (file_exists($appAlchemyFile) && !$this->option('force'))) {
            \Leaf\FS\File::copy(
                dirname(__DIR__) . '/setup/alchemy.yml',
                getcwd() . '/alchemy.yml',
            );
        }

        $this->updateComposerJson();
        $this->updateGitIgnore();

        $this->writeln('<info>Alchemy installed successfully.</info>');

        return 0;
    }

    protected function updateComposerJson()
    {
        $appComposerJson = json_decode(file_get_contents(getcwd() . '/composer.json'), true);

        $composerConfig = $appComposerJson['config'] ?? [];
        $composerConfigPlugins = $composerConfig['allow-plugins'] ?? [];

        $appComposerJson['scripts']['alchemy'] = './vendor/bin/alchemy setup';
        $appComposerJson['scripts']['test'] = './vendor/bin/alchemy setup --test';
        $appComposerJson['scripts']['lint'] = './vendor/bin/alchemy setup --lint';
        $appComposerJson['scripts']['refactor'] = './vendor/bin/alchemy setup --refactor';
        $appComposerJson['scripts']['actions'] = './vendor/bin/alchemy setup --actions';

        $appComposerJson['config'] = array_merge($composerConfig, [
            'allow-plugins' => array_merge($composerConfigPlugins, [
                'pestphp/pest-plugin' => true,
            ]),
        ]);

        file_put_contents(getcwd() . '/composer.json', json_encode($appComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function updateGitIgnore()
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
}
