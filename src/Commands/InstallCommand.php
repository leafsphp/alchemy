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
        \Leaf\Alchemy\Core::installComposerScripts();
    }

    protected function updateGitIgnore()
    {
        \Leaf\Alchemy\Core::updateGitIgnore();
    }
}
