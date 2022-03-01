<?php

namespace Leaf\Alchemy\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class InstallCommand extends Command
{
	protected static $defaultName = 'install';

	protected function configure()
	{
		$this
			->setHelp("Install a new package")
			->setDescription("Add a new package to your leaf app")
			->addArgument("package", InputArgument::REQUIRED, "package to install")
			->addArgument("version", InputArgument::OPTIONAL, "version to install");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$package = $input->getArgument("package");
		if (strpos($package, '/') == false) $package = "leafs/$package";

		$version = $input->getArgument("version") ?? null;

		$output->writeln("<info>Installing $package...</info>");
		$composer = $this->findComposer();
		$process = Process::fromShellCommandline(
			"$composer require $package $version", null, null, null, null
		);

		$process->run(function ($type, $line) use ($output) {
			$output->write($line);
		});

		if ($process->isSuccessful()) {
			$output->writeln("<comment>$package installed successfully!</comment>");
			return 0;
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
