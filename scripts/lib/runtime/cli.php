<?php
/**
 * CLI entrypoint helpers loaded by the shared runtime facade.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssPrepareCliEntrypoint(bool $rootRequired = false, array $argvAppend = []): void
{
    pmssRequireCli();
    if ($rootRequired) requireRoot();
    if (empty($argvAppend)) return;
    if (!isset($GLOBALS['argv']) || !is_array($GLOBALS['argv'])) $GLOBALS['argv'] = $_SERVER['argv'] ?? [];
    if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) $_SERVER['argv'] = $GLOBALS['argv'];
    foreach ($argvAppend as $arg) {
        $arg = (string) $arg;
        $GLOBALS['argv'][] = $arg;
        $_SERVER['argv'][] = $arg;
    }
}

/** Keep wrapper delegation inside the caller-provided script tree. */
function pmssCliEntrypointRelativePathIsSafe(string $relativePath): bool
{
    if ($relativePath === '' || $relativePath[0] === '/' || preg_match('/[\r\n\0\\\\]/', $relativePath) === 1) {
        return false;
    }

    foreach (explode('/', $relativePath) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

function pmssRequireCliEntrypointScript(string $baseDir, string $relativePath, bool $rootRequired = false, array $argvAppend = []): void
{
    pmssPrepareCliEntrypoint($rootRequired, $argvAppend);
    $scriptPath = pmssCliEntrypointScriptResolve($baseDir, $relativePath);
    if (pmssCliEntrypointScriptStartsWithShebang($scriptPath)) {
        pmssRunCliEntrypointScriptProcess($scriptPath);
    }

    require_once $scriptPath;
}

function pmssCliEntrypointScriptResolve(string $baseDir, string $relativePath): string
{
    if (!pmssCliEntrypointRelativePathIsSafe($relativePath)) {
        throw new RuntimeException('Unsafe CLI entrypoint relative path');
    }

    if ($baseDir === '' || pmssFilesystemPathHasNulByte($baseDir)) {
        throw new RuntimeException('Unsafe CLI entrypoint base directory');
    }

    $baseReal = realpath($baseDir);
    if ($baseReal === false || $baseReal === DIRECTORY_SEPARATOR || !is_dir($baseReal)) {
        throw new RuntimeException('Unsafe CLI entrypoint base directory');
    }

    $scriptReal = realpath(rtrim($baseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relativePath);
    if ($scriptReal === false || !is_file($scriptReal)) {
        throw new RuntimeException('Unable to resolve CLI entrypoint script');
    }

    // Symlinked path segments must not escape the wrapper-owned script tree.
    $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if (strpos($scriptReal, $basePrefix) !== 0) {
        throw new RuntimeException('Unsafe CLI entrypoint script path');
    }

    return $scriptReal;
}

/** Detect executable PHP scripts that cannot be safely included before strict_types. */
function pmssCliEntrypointScriptStartsWithShebang(string $scriptPath): bool
{
    $handle = @fopen($scriptPath, 'rb');
    if (!is_resource($handle)) {
        return false;
    }

    $prefix = @fread($handle, 2);
    @fclose($handle);

    return $prefix === '#!';
}

/** @param array<int, string> $argv */
function pmssCliEntrypointScriptCommand(string $scriptPath, array $argv): string
{
    $command = escapeshellarg('php').' '.escapeshellarg($scriptPath);
    for ($i = 1, $argc = count($argv); $i < $argc; $i++) {
        $command .= ' '.escapeshellarg((string) $argv[$i]);
    }

    return $command;
}

function pmssRunCliEntrypointScriptProcess(string $scriptPath): void
{
    $argv = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
    $command = pmssCliEntrypointScriptCommand($scriptPath, is_array($argv) ? $argv : []);
    $rc = 1;
    passthru($command, $rc);
    exit((int) $rc);
}

function pmssRunCliEntrypoint(string $scriptPath, callable $main): void
{
    if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === $scriptPath) exit((int) $main());
}

function pmssRunCliEntrypointWithArgv(string $scriptPath, callable $main): void
{
    pmssRunCliEntrypoint($scriptPath, static function () use ($main): int {
        $argv = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
        return (int) $main(is_array($argv) ? $argv : []);
    });
}

function pmssRunCliProcessorEntrypoint(string $scriptPath, object $processor): void { pmssRunCliEntrypointWithArgv($scriptPath, static function (array $argv) use ($processor, $scriptPath): int { return (int) $processor->runCli($argv, (string) ($argv[0] ?? $scriptPath)); }); }
