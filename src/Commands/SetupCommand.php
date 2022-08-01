<?php

namespace Leaf\Alchemy\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SetupCommand extends Command
{
	/**
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('setup')
			->setDescription('Setup default alchemy tests')
			->addOption('pest', null, InputOption::VALUE_NONE, 'Setup tests with Pest PHP (default)')
			->addOption('phpunit', null, InputOption::VALUE_NONE, 'Setup tests with PHPUnit')
			->addOption('replace', 'r', InputOption::VALUE_NONE, 'Replace test or tests folder if it exists');
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

		if ($input->getOption('phpunit')) {
			$engine = 'phpunit';
		}

		$output->writeln("<comment>Using @$engine.</comment>");

		\Leaf\FS::superCopy(
			dirname(__DIR__) . "/setup/$engine",
			getcwd(),
		);

		$output->writeln('<info>Tests setup successfully.</info>');

		return 0;
	}
}
