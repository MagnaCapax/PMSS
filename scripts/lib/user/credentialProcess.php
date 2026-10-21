<?php
/**
 * Deliver a credential to a child process without placing it in the command.
 *
 * @license GPL-3.0-only
 */

/** Run a string command, passing one credential line on stdin when supplied. */
function pmssCredentialProcessRun(string $command, ?string $credential, ?array &$output = null): int
{
    $capture = $output !== null;
    if ($capture) {
        $output = [];
    }
    if (strpos($command, "\0") !== false || ($credential !== null && preg_match('/[\r\n]/', $credential))) {
        return 1;
    }
    $descriptors = $capture
        ? [0 => ['pipe', 'r'], 1 => ['pipe', 'w']]
        : [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR];
    $process = @proc_open($capture ? $command.' 2>&1' : $command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return 1;
    }

    $payload = $credential === null ? '' : $credential."\n";
    $written = 0;
    while ($written < strlen($payload)) {
        $chunk = @fwrite($pipes[0], substr($payload, $written));
        if (!is_int($chunk) || $chunk < 1) {
            break;
        }
        $written += $chunk;
    }
    fclose($pipes[0]);
    if ($capture) {
        $text = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (is_string($text) && trim($text) !== '') {
            $output = explode("\n", trim($text));
        }
    }
    $status = proc_close($process);
    return $written === strlen($payload) ? $status : 1;
}
