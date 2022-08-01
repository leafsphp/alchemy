<?php

namespace Leaf\Alchemy\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EjectCommand extends Command
{
	/**
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('config:eject')
			->setDescription('Switch from alchemy to pest or phpunit');
	}

	/**
	 * Execute the command.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$engine = 'pest';
		$configFile = getcwd() . "/alchemy.config.php";
		$config = [];

		if (file_exists($configFile)) {
			$output->writeln('<comment>Using existing alchemy.config.php...</comment>');
			$config = require $configFile;
		} else {
			$config = require dirname(__DIR__) . "/setup/pest/alchemy.config.php";
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

		$phpunitXml = \Leaf\FS::readFile(dirname(__DIR__) . "/setup/stubs/phpunit.xml.stub");
		$phpunitXml = str_replace(
			['CONFIG.XMLNSXSI', 'CONFIG.NONSLOCATION', 'CONFIG.BOOTSTRAP', 'CONFIG.COLORS', 'CONFIG.TESTSUITES', 'COVERAGE.PROCESSUNCOVEREDFILES', 'COVERAGE.INCLUDES'],
			[$config['xmlns:xsi'], $config['xsi:noNamespaceSchemaLocation'], $config['bootstrap'], $config['colors'] ? 'true' : 'false', $testSuites, $config['coverage']['processUncoveredFiles'] ? 'true' : 'false', $coverageIncludes],
			$phpunitXml
		);

		\Leaf\FS::writeFile(getcwd() . '/phpunit.xml', $phpunitXml);
		\Leaf\FS::deleteFile(getcwd() . '/alchemy.config.php');

		$output->writeln('<info>Config exported successfully.</info>');

		return 0;
	}
}
