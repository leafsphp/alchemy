<?php

namespace Leaf\Alchemy\Commands;

class AllCommand extends SetupCommand
{
    protected $signature = 'all
        {--c|check? : Check without changing anything (used in CI)}
        {--flags? : Add flags to the command being run separated by commas}';
    protected $description = 'Run everything configured in alchemy.yml';
    protected $help = 'Runs every tool present in alchemy.yml — tests, lint, refactor, analyse — and generates CI files when an actions section exists. Sections that aren\'t in the file don\'t run.';

    protected function handle(): int
    {
        if (!file_exists(getcwd() . '/alchemy.yml')) {
            $this->writeln('<comment>No alchemy.yml found. Run `alchemy init` first — `alchemy all` runs what your file configures.</comment>');
            return 1;
        }

        $this->loadAlchemyConfig();

        return $this->runAll();
    }
}
