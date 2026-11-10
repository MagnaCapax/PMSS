<?php
/** Local support mail process lifetime. @license GPL-3.0-only */
require_once __DIR__.'/stream.php';
require_once __DIR__.'/envelope.php';
require_once dirname(__DIR__).'/runtime/commandPipes.php';

/**
 * Deliver the support message via a local sendmail-compatible binary.
 */
function pmssSupportMailSendViaSendmail(string $sendmailPath, array $envelope): void
{
    $envelope = pmssSupportMailEnvelopeNormalize($envelope);
    $spec = pmssProcessPipeDescriptorSpec('w');
    $process = @proc_open(escapeshellarg($sendmailPath).' -t -i', $spec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start sendmail transport.');
    }
    try {
        // Own the process and every returned pipe before inspecting the launch result.
        if (!isset($pipes[0], $pipes[1], $pipes[2])
            || !pmssStreamHandleIsOpen($pipes[0])
            || !pmssStreamHandleIsOpen($pipes[1])
            || !pmssStreamHandleIsOpen($pipes[2])) {
            throw new RuntimeException('Sendmail transport did not provide usable pipes.');
        }

        pmssSupportStreamWriteAll($pipes[0], (string) $envelope['data'], 'support mail message to sendmail');
        fclose($pipes[0]);
        unset($pipes[0]);

        $stderr = (string) stream_get_contents($pipes[2]);

        pmssProcessPipesClose([$pipes[1], $pipes[2]]);
        unset($pipes[1], $pipes[2]);

        $rc = proc_close($process);
        $process = null;
    } finally {
        try {
            pmssProcessPipesClose($pipes);
        } finally {
            // A pipe wrapper's close failure must not skip reaping the child.
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }

    if ($rc !== 0) {
        throw new RuntimeException('Support mail delivery failed: '.trim((string) $stderr));
    }
}
