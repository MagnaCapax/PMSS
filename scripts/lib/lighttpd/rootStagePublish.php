<?php
/** Private stderr capture for managed-file publication. @license GPL-3.0-only */

require_once __DIR__.'/../log.php';

/** Run the pinned publish command without exposing content or retaining unbounded stderr in memory. */
function pmssRootStagePublishRun(string $command, ?string &$failure = null): bool
{
    $failure = 'stage=publish-stderr rc=not-started';
    $stderr = @tmpfile();
    if (!is_resource($stderr)) return false;
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
    } finally {
        // Account-influenced paths may appear here: bound and sanitize before logging.
        $excerpt = @rewind($stderr) ? trim(pmssLogControlCharactersReplace((string) @fread($stderr, 300))) : '';
        @fclose($stderr);
        if ($failure !== '' && $excerpt !== '') $failure .= ' stderr='.$excerpt;
    }
}
