<?php

namespace Leaf\Alchemy\Commands;

class FmtCommand extends SetupCommand
{
    protected $signature = 'fmt';
    protected $description = 'Fix code style issues';
    protected $help = 'Runs your linter in fix mode and rewrites files to match your style config.';
    protected $modeOverride = 'fix';

    protected function handle(): int
    {
        $this->loadAlchemyConfig();

        return $this->runLinter();
    }
}
