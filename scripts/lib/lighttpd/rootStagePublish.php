<?php
/** Private stderr capture for managed-file publication. @license GPL-3.0-only */

require_once __DIR__.'/../log.php';
require_once __DIR__.'/../runtime/commandProcess.php';

/** Run the pinned publish command without exposing content or retaining unbounded stderr in memory. */
function pmssRootStagePublishRun(string $command, ?string &$failure = null): bool
{
    $failure = 'stage=publish-stderr rc=not-started';
    $stderr = @tmpfile();
    if (!is_resource($stderr)) return false;
    $process = null;
    try {
        // As in accountPath.php, only the private descriptor survives launch.
        if (!@unlink(stream_get_meta_data($stderr)['uri'])) return false;
        $failure = 'stage=publish-launch rc=not-started';
        $process = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => $stderr,
        ], $pipes);
        if (!is_resource($process)) return false;
        $status = @proc_close($process);
        $failure = $status === 0 ? '' : 'stage=publish rc='.$status;
        return $status === 0;
    } catch (\Throwable $error) {
        // A failed reap can leave this invocation's child owned and running.
        if (is_resource($process)) {
            pmssProcessTerminateAndClose($process);
        }
        throw $error;
    } finally {
        // Account-influenced paths may appear here: bound and sanitize before logging.
        try {
            $excerpt = @rewind($stderr) ? trim(pmssLogControlCharactersReplace((string) @fread($stderr, 300))) : '';
        } finally {
            // Diagnostic failures must release the private capture before propagating.
            @fclose($stderr);
        }
        if ($failure !== '' && $excerpt !== '') $failure .= ' stderr='.$excerpt;
    }
}
