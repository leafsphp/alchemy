<?php

namespace Leaf\Alchemy\Commands;

class AnalyseCommand extends SetupCommand
{
    protected $signature = 'analyse';
    protected $description = 'Run static analysis with PHPStan';
    protected $help = 'Installs PHPStan if needed and analyses your code at the level configured in the `analyse` section of alchemy.yml.';

    protected function handle(): int
    {
        $this->loadAlchemyConfig();

        return $this->runAnalyser();
    }
}
