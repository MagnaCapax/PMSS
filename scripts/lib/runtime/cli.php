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

function pmssRequireCliEntrypointScript(string $baseDir, string $relativePath, bool $rootRequired = false, array $argvAppend = []): void
{
    pmssPrepareCliEntrypoint($rootRequired, $argvAppend);
    require_once rtrim($baseDir, '/').'/'.ltrim($relativePath, '/');
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
