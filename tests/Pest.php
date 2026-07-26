<?php

/*
|--------------------------------------------------------------------------
| Alchemy test helpers
|--------------------------------------------------------------------------
|
| Every test runs inside a throwaway sandbox directory so generators and
| commands (which work against getcwd()) never touch the real repo.
|
*/

function sandboxSetup(): void
{
    $GLOBALS['__alchemyTestCwd'] = getcwd();
    $sandbox = sys_get_temp_dir() . '/alchemy-test-' . bin2hex(random_bytes(6));

    mkdir($sandbox, 0777, true);
    chdir($sandbox);

    $GLOBALS['__alchemySandbox'] = $sandbox;
}

function sandboxTeardown(): void
{
    chdir($GLOBALS['__alchemyTestCwd']);
    removeDirectory($GLOBALS['__alchemySandbox']);
}

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = "$dir/$item";
        is_dir($path) && !is_link($path) ? removeDirectory($path) : unlink($path);
    }

    rmdir($dir);
}

/**
 * Run the real alchemy binary inside the current sandbox.
 * @return array{0: int, 1: string} exit code and combined output
 */
function alchemy(string $args): array
{
    $bin = dirname(__DIR__) . '/bin/alchemy';

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . " $args 2>&1", $output, $exit);

    return [$exit, implode("\n", $output)];
}

function writeComposerJson(array $extra = []): void
{
    file_put_contents(
        getcwd() . '/composer.json',
        json_encode(array_merge(['name' => 'alchemy/sandbox'], $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}
