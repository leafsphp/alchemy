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
		$needsUpdate = Package::updateAvailable();

		if ($needsUpdate) {
			$output->writeln("<comment>Update found, updating to the latest stable version...</comment>");
			$updateProcess = Process::fromShellCommandline("php " . dirname(__DIR__) . "/bin/leaf update");

			$updateProcess->run();

			if ($updateProcess->isSuccessful()) {
				$output->writeln("<info>Leaf CLI updated successfully, building your app...</info>\n");
			}
		}

		$name = $input->getArgument('project-name');
		$directory = $name !== '.' ? getcwd() . '/' . $name : getcwd();

		if (!$input->getOption('force')) {
			$this->verifyApplicationDoesntExist($directory);
		}

		$preset = $this->getPreset($input, $output);
		$this->getVersion($input, $output);

		$output->writeln(
			"\n<comment> - </comment>Creating \""
			. basename($directory) . "\" in <info>./"
			. basename(dirname($directory)) .
			"</info> using <info>$preset@" . $this->version .  "</info>."
		);

		if ($preset === "leaf") {
			return $this->leaf($input, $output, $directory);
		}

		$installCommand = $composer . " create-project leafs/$preset " . basename($directory);

		if ($this->version === "v3") {
			$installCommand .= " v3.x-dev";
		}

		$commands = [
			$installCommand,
		];

		if ($input->getOption('no-ansi')) {
			$commands = array_map(function ($value) {
				return $value . ' --no-ansi';
			}, $commands);
		}

		if ($input->getOption('quiet')) {
			$commands = array_map(function ($value) {
				return $value . ' --quiet';
			}, $commands);
		}

		$process = Process::fromShellCommandline(
			implode(' && ', $commands), dirname($directory), null, null, null
		);

		if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
			$process->setTty(true);
		}

		$process->run(function ($type, $line) use ($output) {
			$output->write($line);
		});

		if ($process->isSuccessful()) {
			$output->writeln("\nYou can start with:");
			$output->writeln("\n  <info>cd</info> " . basename($directory));
			$output->writeln("  <info>leaf app:serve</info>");
			$output->writeln("\nHappy gardening!");
		}

		return 0;
	}

	/**
	 * Verify that the application does not already exist.
	 *
	 * @param string $directory
	 * @return void
	 */
	protected function verifyApplicationDoesntExist(string $directory)
	{
		if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
			throw new RuntimeException('Application already exists!');
		}
	}

	/**
	 * Get the version that should be downloaded.
	 *
	 * @param InputInterface $input
	 * @param $output
	 * @return void
	 */
	protected function getVersion(InputInterface $input, $output)
	{
		if ($input->getOption("v3")) {
			$this->version = "v3";
			return;
		}

		if ($input->getOption("v2")) {
			$this->version = "v2";
			return;
		}

		$this->version = $this->scaffoldVersion($input, $output);
	}

	/**
	 * Get the preset that should be downloaded.
	 *
	 * @param InputInterface $input
	 * @param $output
	 * @return string
	 */
	protected function getPreset(InputInterface $input, $output): string
	{
		if ($input->getOption("basic")) {
			return "leaf";
		}

		if ($input->getOption("api")) {
			return "api";
		}

		if ($input->getOption("mvc")) {
			return "mvc";
		}

		if ($input->getOption("skeleton")) {
			return "skeleton";
		}

		$preset = $this->scaffold($input, $output);
		$output->writeln("\n<comment> - </comment>Using preset $preset\n");

		if ($preset == "leaf api") {
			return "api";
		}

		if ($preset == "leaf mvc") {
			return "mvc";
		}

		return $preset;
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
