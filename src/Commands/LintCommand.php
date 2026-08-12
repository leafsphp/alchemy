<?php

namespace Leaf\Alchemy\Commands;

class LintCommand extends SetupCommand
{
    protected $signature = 'lint
        {--flags= : Add flags to the linter, separated by commas (e.g. --flags=dirty)}';
    protected $description = 'Check code style without changing anything (use fmt to fix)';
    protected $help = 'Runs your linter in check mode: reports style violations and exits non-zero without touching your code. Use `alchemy fmt` to apply fixes.';
    protected $modeOverride = 'check';

    protected function handle(): int
    {
        $this->loadAlchemyConfig();

        return $this->runLinter();
    }
}
