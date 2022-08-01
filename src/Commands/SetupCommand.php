<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Alchemy\Utils\Package;
use Leaf\FS;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\Process;

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
		$composer = $this->findComposer();
		$directory = getcwd() . '/tests';
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
