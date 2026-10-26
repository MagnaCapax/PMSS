<?php
/**
 * Deliver a credential to a child process without placing it in the command.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../runtime/filesystem.php';

/**
 * Run a command with a credential line on stdin; requested capture normally merges both streams.
 * Stderr-only capture keeps credential-bearing stdout on the inherited console.
 * An optional deadline bounds the child and descendants, with injectable clock/pause operations.
 */
function pmssCredentialProcessRun(string $command, ?string $credential, ?array &$output = null, bool $stderrOnly = false, ?float $timeout = null, array $ops = []): int
{
    if ($timeout !== null) {
        require_once __DIR__.'/credentialProcessTimed.php';
        return pmssCredentialProcessRunTimed($command, $credential, $output, $stderrOnly, $timeout, $ops);
    }
    $capture = $output !== null;
    if ($capture) {
        $output = [];
    }
    if (strpos($command, "\0") !== false || ($credential !== null && preg_match('/[\r\n]/', $credential))) {
        return 1;
    }
    $outputFd = $stderrOnly ? 2 : 1;
    $descriptors = $capture
        ? [0 => ['pipe', 'r'], $outputFd => ['pipe', 'w']]
        : [0 => ['pipe', 'r']]; // fds 1-2 inherited; passing STDOUT/STDERR would seek a redirected log back
    $process = @proc_open($capture && !$stderrOnly ? $command.' 2>&1' : $command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return 1;
    }

    $payload = $credential === null ? '' : $credential."\n";
    $complete = pmssStreamWriteAll($pipes[0], $payload);
    fclose($pipes[0]);
    if ($capture) {
        $text = stream_get_contents($pipes[$outputFd]);
        fclose($pipes[$outputFd]);
        if (is_string($text) && trim($text) !== '') {
            $output = explode("\n", trim($text));
        }
    }
    $status = proc_close($process);
    return $complete ? $status : 1;
}
