<?php

namespace Leaf\Alchemy\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class UpdateCommand extends Command
{
	protected static $defaultName = 'update';

	protected function configure()
	{
		$this
			->setHelp("Update leaf cli")
			->setDescription("Update leaf cli to the latest version");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$composer = $this->findComposer();
		$uninstall = Process::fromShellCommandline("$composer global remove leafs/cli --no-update --no-install");
		$install = Process::fromShellCommandline("$composer global require leafs/cli", null, null, null, null);

		$uninstall->run(function ($type, $line) use ($output) {
			$output->write($line);
		});

		if ($uninstall->isSuccessful()) {
			sleep(1);

			$install->run(function ($type, $line) use ($output) {
				$output->write($line);
			});

			if ($install->isSuccessful()) {
				$output->writeln("<info>Leaf CLI installed successfully!</info>");
				return 0;
			}
		}

		return 1;
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
