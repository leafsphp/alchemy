<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Alchemy\Core;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class SetupCommand extends Command
{
  protected $output;
  protected $input;

  /**
   * Configure the command options.
   *
   * @return void
   */
  protected function configure()
  {
    $this
      ->setName('setup')
      ->setDescription('Setup work environment based on Alchemy configuration')
      ->addOption('lint', 'l', InputOption::VALUE_NONE, 'Run only linter')
      ->addOption('test', 't', InputOption::VALUE_NONE, 'Run only tests')
      ->addOption('actions', 'gh', InputOption::VALUE_NONE, 'Generate GitHub actions')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace test or tests folder if it exists');
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
    $this->input = $input;
    $this->output = $output;

    Core::set(Yaml::parseFile(getcwd() . '/alchemy.yml'));
    \Leaf\FS::createFolder(getcwd() . '/.alchemy');

    if ($input->getOption('test')) {
      return $this->runTests();
    }

    if ($input->getOption('lint')) {
      return $this->runLinter();
    }

    if ($input->getOption('actions')) {
      return $this->generateActions();
    }

    if (!$input->getOption('test') && !$input->getOption('lint') && !$input->getOption('actions')) {
      $this->runTests();
      $this->runLinter();
      $this->generateActions();
    }

    $output->writeln('<info>Alchemy setup successfully.</info>');

    return 0;
  }

  protected function runTests()
  {
    $config = Core::get('tests');

    $engine = $config['engine'] ?? 'pest';
    $engineInstaller = $engine === 'pest' ? '\'pestphp/pest:*\' --with-all-dependencies' : '\'phpunit/phpunit:*\'';

    if (!file_exists(getcwd() . "/vendor/bin/$engine")) {
      $engineInstallProcess = Process::fromShellCommandline(
        "composer require $engineInstaller --dev",
        null,
        null,
        null,
        null
      );

      // $engineInstallProcess->setTty(true);

      $this->output->writeln("<info>Setting up tests with $engine...</info>\n");

      $engineInstallProcess->run(function ($type, $line) {
        $this->output->write($line);
      });

      if (!$engineInstallProcess->isSuccessful()) {
        $this->output->writeln("<error>Couldn\'t install $engine. Check your connection and try again.</error>");

        return 1;
      }

      $this->output->writeln("<info>$engine installed successfully!</info>");
    }

    if (!is_dir(getcwd() . '/' . ($config['paths'][0] ?? '/tests'))) {
      $this->output->writeln('<info>Writing sample tests...</info>');

      \Leaf\FS::superCopy(
        dirname(__DIR__) . "/setup/$engine",
        getcwd(),
      );
    }

    Core::generateTestFiles();

    $this->output->writeln('<comment>Running your tests...</comment>');

    $flags = $engine === 'pest' ? '--colors=always' : '';

    $testProcess = Process::fromShellCommandline(
      getcwd() . "/vendor/bin/$engine $flags",
      null,
      null,
      null,
      null
    );

    // $testProcess->setTty(true);

    $testProcess->run(function ($type, $line): void {
      $this->output->write($line);
    });

    \Leaf\FS::deleteFile(getcwd() . '/phpunit.xml');

    return 0;
  }

  protected function runLinter()
  {
    if (!file_exists(getcwd() . '/vendor/bin/php-cs-fixer')) {
      $engineInstallProcess = Process::fromShellCommandline(
        'composer require friendsofphp/php-cs-fixer --dev',
        null,
        null,
        null,
        null
      );

      // $engineInstallProcess->setTty(true);

      $this->output->writeln("<info>Setting up linting with php-cs-fixer...</info>\n");

      $engineInstallProcess->run(function ($type, $line) {
        $this->output->write($line);
      });

      if (!$engineInstallProcess->isSuccessful()) {
        $this->output->writeln('<error>Couldn\'t install PHP-CS-Fixer. Check your connection and try again.</error>');

        return 1;
      }

      $this->output->writeln('<info>Linter installed successfully!</info>');
    }

    Core::generateLintFiles();

    $this->output->writeln("<comment>Running linter...</comment>\n");

    $testProcess = Process::fromShellCommandline(
      getcwd() . '/vendor/bin/php-cs-fixer fix --config=.php_cs.dist.php --allow-risky=yes',
      null,
      null,
      null,
      null
    );

    // $testProcess->setTty(true);

    $testProcess->run(function ($type, $line): void {
      $this->output->write($line);
    });

    \Leaf\FS::deleteFile(getcwd() . '/.php_cs.dist.php');

    if (file_exists(getcwd() . '/.php-cs-fixer.cache')) {
      \Leaf\FS::moveFile(getcwd() . '/.php-cs-fixer.cache', getcwd() . '/.alchemy/.php-cs-fixer.cache');
    }

    return 0;
  }

  protected function generateActions()
  {
    $config = Core::get('actions');
    $actionToRun = $config['run'] ?? [];

    \Leaf\FS::createFolder(getcwd() . '/.github');

    foreach ($actionToRun as $action) {
      $actionFile = getcwd() . "/.github/workflows/$action.yml";
      $phpVersions = $config['php']['versions'] ?? ['8.3'];
      $phpExtensions = $config['php']['extensions'] ?? 'json, zip';
      $os = $config['os'] ?? ['ubuntu-latest'];
      $events = $config['events'] ?? ['push'];
      $failFast = $config['fail-fast'] ?? true;

      if (!file_exists($actionFile)) {
        $this->output->writeln("<info>Writing GitHub action $action.yml...</info>");

        $actionStub = \Leaf\FS::readFile(dirname(__DIR__) . "/setup/workflows/$action.yml");

        $actionStub = str_replace(
          ['ACTIONS.PHP.VERSIONS', 'ACTIONS.PHP.EXTENSIONS', 'ACTIONS.OS', 'ACTIONS.EVENTS', 'ACTIONS.FAILFAST'],
          [Core::unJsonify($phpVersions, 0), $phpExtensions, Core::unJsonify($os, 0), Core::unJsonify($events, 0), $failFast ? 'true' : 'false'],
          $actionStub
        );

        \Leaf\FS::writeFile($actionFile, $actionStub);
      }
    }

    return 0;
  }
}
