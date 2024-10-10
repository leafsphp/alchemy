<?php

namespace Leaf\Alchemy\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
	/**
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('install')
			->setDescription('Generate base alchemy files')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace alchemy files if they already exist');
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
		if (file_exists(getcwd() . '/alchemy.yml') && !$input->getOption('force')) {
			$output->writeln('<comment>Alchemy already installed.</comment>');

			return 0;
		}
		
		copy(
			dirname(__DIR__) . '/setup/pest/alchemy.yml',
			getcwd() . '/alchemy.yml',
		);

		$output->writeln('<info>Alchemy installed successfully.</info>');

		return 0;
	}
}
