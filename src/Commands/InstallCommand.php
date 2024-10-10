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
		$appAlchemyFile = (getcwd() . '/alchemy.yml');
		if (!file_exists($appAlchemyFile) || (file_exists($appAlchemyFile) && !$input->getOption('force'))) {
			copy(
				dirname(__DIR__) . '/setup/pest/alchemy.yml',
				getcwd() . '/alchemy.yml',
			);
		}

		$this->updateComposerJson();

		$output->writeln('<info>Alchemy installed successfully.</info>');

		return 0;
	}

	protected function updateComposerJson()
	{
		$appComposerJson = json_decode(file_get_contents(getcwd() . '/composer.json'), true);

		$composerConfig = $appComposerJson['config'] ?? [];
		$composerConfigPlugins = $composerConfig['allow-plugins'] ?? [];

		$appComposerJson['scripts']['alchemy'] = './vendor/bin/alchemy setup';
		$appComposerJson['scripts']['test'] = './vendor/bin/alchemy test';
		$appComposerJson['scripts']['lint'] = './vendor/bin/alchemy lint';
		$appComposerJson['config'] = array_merge($composerConfig, [
			'allow-plugins' => array_merge($composerConfigPlugins, [
				'pestphp/pest-plugin' => true,
			]),
		]);

		file_put_contents(getcwd() . '/composer.json', json_encode($appComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}
}
