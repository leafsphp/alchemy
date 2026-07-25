<?php

namespace Leaf\Alchemy\Commands;

class RefactorCommand extends SetupCommand
{
    protected $signature = 'refactor
        {--c|check? : Report pending refactors without changing anything (used in CI)}';
    protected $description = 'Apply automated refactors with Rector';
    protected $help = 'Installs Rector if needed and applies the refactors configured in the `refactor` section of alchemy.yml. Use --check in CI to fail on pending refactors.';

    protected function handle(): int
    {
        $this->loadAlchemyConfig();

        return $this->runRefactor();
    }
}
