<?php

namespace Leaf\Alchemy\Commands;

class TestCommand extends SetupCommand
{
    protected $signature = 'test
        {--f|force? : Replace test or tests folder if it exists}
        {--flags= : Add flags to the test engine separated by commas}';
    protected $description = 'Run your tests';
    protected $help = 'Installs your test engine if needed, generates config from alchemy.yml and runs your tests.';

    protected function handle(): int
    {
        set_time_limit(0);
        $this->loadAlchemyConfig();

        return $this->runTests();
    }
}
