<?php

namespace Leaf\Alchemy\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RunCommand extends Command
{
	protected static $defaultName = 'run';

	protected function configure()
	{
		$this
			->setHelp("Run tests in your app")
			->setDescription("Run your application tests")
			->addOption('pest', null, InputOption::VALUE_NONE, 'Run tests with Pest PHP (default)')
			->addOption('phpunit', null, InputOption::VALUE_NONE, 'Run tests with PHPUnit');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$engine = 'pest';
		$composer = $this->findComposer();
		$configFile = getcwd() . "/alchemy.config.php";
		$config = [];

		if ($input->getOption('phpunit')) {
			$engine = 'phpunit';
		}

		if (!$input->getOption('phpunit') && !$input->getOption('pest') && file_exists($configFile)) {
			// $output->writeln('<comment>Using existing alchemy.config.php</comment>');
			$config = require $configFile;
			$engine = $config['engine'];
		}

		if ($engine === 'pest' && !file_exists(getcwd() . '/vendor/bin/pest')) {
			$output->writeln("<info>Pest PHP not found in project. Attempting install...</info>");

			$pestInstallProcess = Process::fromShellCommandline(
				"$composer require pestphp/pest --dev --with-all-dependencies",
				null,
				null,
				null,
				null
			);

			$pestInstallProcess->run(function ($type, $line) use ($output) {
				$output->write($line);
			});

			if ($pestInstallProcess->isSuccessful()) {
				$output->writeln("<info>Pest installed successfully!</info>");
				$output->writeln("<comment>Running your tests...</comment>");
			} else {
				$output->writeln("<error>Couldn't install Pest PHP. Check your connection and try again.</error>");
			}
		}

		if (!file_exists(getcwd() . '/vendor/bin/phpunit')) {
			$output->writeln("<info>PHP Unit not found in project. Attempting install...</info>");

			$phpunitInstallProcess = Process::fromShellCommandline(
				"$composer require phpunit/phpunit --dev",
				null,
				null,
				null,
				null
			);

			$phpunitInstallProcess->run(function ($type, $line) use ($output) {
				$output->write($line);
			});

			if ($phpunitInstallProcess->isSuccessful()) {
				$output->writeln("<info>PHPUnit installed successfully!</info>");
				$output->writeln("<comment>Running your tests...</comment>");
			} else {
				$output->writeln("<error>Couldn't install PHPUnit. Check your connection and try again.</error>");
			}
		}

		shell_exec(PHP_BINARY . ' ' . dirname(__DIR__, 2) . "/bin/alchemy config:export");

		$testProcess = Process::fromShellCommandline(
			$engine === 'pest' ? getcwd() . '/vendor/bin/pest' : getcwd() . '/vendor/bin/phpunit',
			null,
			null,
			null,
			null
		);

		$testProcess->run(function ($type, $line) use ($output) {
			$output->write($line);
		});

		\Leaf\FS::deleteFile(getcwd() . '/phpunit.xml');

		return $testProcess->isSuccessful() ? 0 : 1;
	}

	/**
	 * Get the composer command for the environment.
	 *
	 * @return string
	 */
	protected function findComposer(): string
	{
		$composerPath = getcwd() . '/composer.phar';

		if (file_exists($composerPath)) {
			return '"' . PHP_BINARY . '" ' . $composerPath;
		}

		return 'composer';
	}
}
