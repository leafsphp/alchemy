<?php

namespace Leaf\Alchemy\Commands;

use Leaf\Alchemy\Core;
use Leaf\Sprout\Command;
use Symfony\Component\Yaml\Yaml;

class EjectCommand extends Command
{
    protected $signature = 'eject
        {--f|force? : Overwrite existing config files}';
    protected $description = 'Leave alchemy: export real config files for your test engine and linter';
    protected $help = 'This command exports your alchemy.yml configuration to a standard phpunit.xml and .php-cs-fixer.dist.php so you can run your engines directly without alchemy. No lock-in.';

    /**
     * Execute the command.
     * @return int
     */
    protected function handle(): int
    {
        $configFile = getcwd() . '/alchemy.yml';

        if (file_exists($configFile)) {
            Core::set(Yaml::parseFile($configFile));
        } else {
            $this->writeln('<comment>No alchemy.yml found, exporting default configuration.</comment>');
            Core::set(Yaml::parseFile(dirname(__DIR__) . '/setup/alchemy.yml'));
        }

        if (
            !$this->option('force')
            && (file_exists(getcwd() . '/phpunit.xml') || file_exists(getcwd() . '/.php-cs-fixer.dist.php'))
        ) {
            $this->writeln('<error>phpunit.xml or .php-cs-fixer.dist.php already exists. Re-run with --force to overwrite.</error>');
            return 1;
        }

        Core::generateTestFiles();
        Core::generateLintFiles();

        // php-cs-fixer picks .php-cs-fixer.dist.php up without any flags
        \Leaf\FS\File::move(getcwd() . '/.php_cs.dist.php', getcwd() . '/.php-cs-fixer.dist.php');

        $this->updateComposerScripts();

        $this->writeln('<info>Config exported: phpunit.xml + .php-cs-fixer.dist.php</info>');
        $this->writeln('<comment>Your composer test/lint scripts now call your engines directly.</comment>');
        $this->writeln('<comment>You can now remove alchemy with `composer remove leafs/alchemy` and delete alchemy.yml.</comment>');

        return 0;
    }

    protected function updateComposerScripts()
    {
        $engine = Core::get('tests')['engine'] ?? 'pest';
        $appComposerJson = json_decode(file_get_contents(getcwd() . '/composer.json'), true);

        $appComposerJson['scripts']['test'] = "./vendor/bin/$engine";
        $appComposerJson['scripts']['lint'] = './vendor/bin/php-cs-fixer fix';
        unset($appComposerJson['scripts']['alchemy'], $appComposerJson['scripts']['actions']);

        file_put_contents(getcwd() . '/composer.json', json_encode($appComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
