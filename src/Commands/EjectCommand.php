<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Sprout\Command;

class EjectCommand extends Command
{
    protected $signature = 'config:eject';
    protected $description = 'Switch from alchemy to pest or phpunit';
    protected $help = 'This command will help you switch from alchemy to pest or phpunit by exporting your configuration to a phpunit.xml file and deleting the alchemy.config.php file.';

    /**
     * Execute the command.
     * @return int
     */
    protected function handle()
    {
        $configFile = getcwd() . '/alchemy.config.php';
        $config = [];

        if (file_exists($configFile)) {
            $this->writeln('<comment>Using existing alchemy.config.php...</comment>');
            $config = require $configFile;
        } else {
            $config = require dirname(__DIR__) . '/setup/pest/alchemy.config.php';
        }

        $testSuiteConfig = $config['testsuites'];
        $testSuites = '';

        foreach ($testSuiteConfig as $testSuiteKey => $testSuiteDir) {
            $testSuites .= "<testsuite name=\"Test Suite $testSuiteDir\"><directory suffix=\".test.php\">$testSuiteDir</directory></testsuite>";
        }

        $testCoverageConfig = $config['coverage']['include'];
        $coverageIncludes = '';

        foreach ($testCoverageConfig as $coverageDir => $coverageKey) {
            $coverageIncludes .= "<directory suffix=\"$coverageKey\">$coverageDir</directory>";
        }

        \Leaf\FS\File::write(getcwd() . '/phpunit.xml', function () use ($config, $testSuites, $coverageIncludes) {
            $phpunitXml = \Leaf\FS\File::read(dirname(__DIR__) . '/setup/stubs/phpunit.xml.stub');
            $phpunitXml = str_replace(
                ['CONFIG.XMLNSXSI', 'CONFIG.NONSLOCATION', 'CONFIG.BOOTSTRAP', 'CONFIG.COLORS', 'CONFIG.TESTSUITES', 'COVERAGE.PROCESSUNCOVEREDFILES', 'COVERAGE.INCLUDES'],
                [$config['xmlns:xsi'], $config['xsi:noNamespaceSchemaLocation'], $config['bootstrap'], $config['colors'] ? 'true' : 'false', $testSuites, $config['coverage']['processUncoveredFiles'] ? 'true' : 'false', $coverageIncludes],
                $phpunitXml
            );

            return $phpunitXml;
        });

        \Leaf\FS\File::delete(getcwd() . '/alchemy.config.php');

        $output->writeln('<info>Config exported successfully.</info>');

        return 0;
    }
}
