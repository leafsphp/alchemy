<?php

namespace Leaf\Alchemy\Commands;

class CiCommand extends SetupCommand
{
    protected $signature = 'ci';
    protected $description = 'Generate CI pipelines for your configured providers';
    protected $help = 'Generates CI config from alchemy.yml for every provider under `actions.provider` (github, gitlab, circleci).';

    protected function handle(): int
    {
        $this->loadAlchemyConfig();

        return $this->generateActions();
    }
}
