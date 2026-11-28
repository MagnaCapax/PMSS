<?php
/** Customer-local support dependencies; no operator bootstrap. @license GPL-3.0-only */

require_once __DIR__.'/localFilesystem.php';

/** Local copy of scripts/lib/runtime/commandLookup.php::pmssCommandBinaryNameIsSafe; preserve its guards and behavior. */
function pmssSupportLocalCommandBinaryNameIsSafe(string $binary): bool { return preg_match('/^[A-Za-z0-9._+-]+$/', $binary) === 1; }

/** Local copy of scripts/lib/runtime/commandLookup.php::pmssCommandPathCandidateIsSafe; preserve its guards and behavior. */
function pmssSupportLocalCommandPathCandidateIsSafe(string $path): bool { return strpos($path, '/') === 0 && strpbrk($path, "\0\r\n") === false; }

/** Local copy of scripts/lib/runtime/commandLookup.php::pmssCommandPath; preserve its guards and behavior. */
function pmssSupportLocalCommandPath(string $binary): string
{
    // trim() removes edge NULs; reject them before they can name another binary.
    if (pmssSupportLocalFilesystemPathHasNulByte($binary)) return '';
    $binary = trim($binary);
    if (!pmssSupportLocalCommandBinaryNameIsSafe($binary)) return '';
    $resolved = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
    // Reject raw NUL bytes before trim() can turn malformed output into a valid path.
    if (!is_string($resolved) || pmssSupportLocalFilesystemPathHasNulByte($resolved)) return '';
    $path = trim($resolved);
    return pmssSupportLocalCommandPathCandidateIsSafe($path) && is_executable($path) ? $path : '';
}

/** Local copy of scripts/lib/runtime/commandShell.php::pmssCommandArgvShellQuote; preserve its guards and behavior. */
function pmssSupportLocalCommandArgvShellQuote(array $argv): string { return implode(' ', array_map(static function ($arg): string { return escapeshellarg((string) $arg); }, $argv)); }

/** Local copy of scripts/lib/runtime/commandPipes.php::pmssProcessPipeDescriptorSpec; preserve its guards and behavior. */
function pmssSupportLocalProcessPipeDescriptorSpec(string $stdinMode = 'r', string $stdoutMode = 'w', string $stderrMode = 'w'): array
{
    return [0 => ['pipe', $stdinMode], 1 => ['pipe', $stdoutMode], 2 => ['pipe', $stderrMode]];
}

/** Local copy of scripts/lib/runtime/commandPipes.php::pmssProcessPipesClose; preserve its guards and behavior. */
function pmssSupportLocalProcessPipesClose(array $pipes): void
{
    $failure = null;
    foreach ($pipes as $pipe) {
        // A failed close can leave its stream live; retry once without delaying the other pipes indefinitely.
        for ($attempt = 0; $attempt < 2 && pmssSupportLocalStreamHandleIsOpen($pipe); $attempt++) {
            try {
                fclose($pipe);
            } catch (\Throwable $error) {
                // One wrapper's failure must not strand the remaining descriptors.
                if ($failure === null) $failure = $error;
            }
        }
    }
    if ($failure !== null) throw $failure;
}

/** Local copy of scripts/lib/runtime/cli.php::pmssCliReturnWithStderr; preserve its guards and behavior. */
function pmssSupportLocalCliReturnWithStderr(string $message, int $exitCode = 1): int
{
    fwrite(STDERR, $message);
    return $exitCode;
}

/** Local copy of scripts/lib/cli/optionParser.php::pmssCliExitWithStderr; preserve its guards and behavior. */
function pmssSupportLocalCliExitWithStderr(string $message, int $exitCode): void { exit(pmssSupportLocalCliReturnWithStderr($message, $exitCode)); }

