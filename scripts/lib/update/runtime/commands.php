<?php
/**
 * Command execution helpers for update workflows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/profile.php';
require_once __DIR__.'/../../runtime.php';

/**
 * Execute a shell command, keeping failures soft.
 *
 * Logged `rc=<n>` values surface the raw shell return code so operators can
 * read `rc=0` as success and treat any non-zero value as a soft failure.
 */
function runStep(string $description, string $command): int
{
    $dryRun  = getenv('PMSS_DRY_RUN') === '1';
    $started = microtime(true);
    $isTty   = function_exists('posix_isatty') && posix_isatty(STDOUT);
    $cReset  = "\033[0m";
    // Emit a start banner for interactive operators so hangs show which step is running.
    if ($isTty) {
        $cBlue = "\033[34m";
        echo $cBlue.'[START] '.$description.$cReset.PHP_EOL;
    } else {
        // Persist start markers to the log for non-interactive runs.
        logMessage('[START] '.$description.' :: '.$command);
    }
    $rc      = $dryRun ? 0 : runCommand($command, false);

    $duration    = microtime(true) - $started;
    // rc reflects the shell return code so downstream logs can inspect rc=0 for success
    $status      = $dryRun ? 'SKIP' : ($rc === 0 ? 'OK' : 'ERR');
    $lastOutput  = $dryRun ? [] : ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? []);
    $stdout      = $lastOutput['stdout'] ?? '';
    $stderr      = $lastOutput['stderr'] ?? '';
    $stderrShort = $stderr !== '' ? preg_replace('/\s+/', ' ', trim(substr($stderr, 0, 300))) : '';
    $stdoutShort = $stdout !== '' ? preg_replace('/\s+/', ' ', trim(substr($stdout, 0, 300))) : '';

    // Fork failures may occur inside nested scripts (e.g. find/chown) while the
    // wrapper command itself still exits rc=0. Detect known strings and emit a
    // non-shell diagnostics snapshot so we have the cgroup/rlimit context.
    if (!$dryRun && function_exists('pmssOutputIndicatesForkFailure') && pmssOutputIndicatesForkFailure($stdout, $stderr)) {
        pmssDumpForkDiagnostics('runStep: '.$description.' :: '.$command, 'logMessage');
    }

    $statusColor = $status === 'ERR' ? "\033[31m" : ($status === 'SKIP' ? "\033[33m" : "\033[32m");

    // Colorize the status block: [STATUS ... rc=N]
    $statusBlock = sprintf('[%s%s%s %.3fs rc=%s%d%s]',
        $statusColor, $status, $cReset,
        $duration,
        $rc === 0 ? "\033[32m" : "\033[31m", $rc, $cReset
    );

    $message = sprintf('%s %s :: %s', $statusBlock, $description, $command);
    if ($status === 'ERR' && $stderrShort !== '') {
        $message .= ' :: ' . "\033[31m" . $stderrShort . $cReset;
    }
    // Use structured logger from logging.php to avoid missing logmsg() when
    // this runtime is invoked outside update.php/bootstrap paths.
    logMessage($message);
    pmssRecordProfile([
        'description'    => $description,
        'command'        => $command,
        'status'         => $status,
        'rc'             => $rc,
        'duration'       => round($duration, 4),
        'dry_run'        => $dryRun,
        'stdout_excerpt' => $stdoutShort,
        'stderr_excerpt' => $stderrShort,
    ]);
    return $rc;
}

if (!function_exists('runUserStep')) {
    /**
     * Run a command while tagging the associated user.
     */
    function runUserStep(string $user, string $description, string $command): int
    {
        return runStep("[user:$user] {$description}", $command);
    }
}

/**
 * Compose a reusable apt-get command prefix.
 */
function aptCmd(string $args): string
{
    return 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none '
        .'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold '
        .$args;
}

/**
 * Build a shell-safe command string from a binary and arguments.
 * Uses escapeshellarg on each argument; intended for simple argv-style
 * commands without shell metacharacter features.
 */
function pmssBuildCommand(string $program, array $args = []): string
{
    $prog = escapeshellcmd($program);
    return empty($args) ? $prog : $prog.' '.implode(' ', array_map(static function ($a) { return escapeshellarg((string) $a); }, $args));
}

/**
 * Log a status line with duration/rc in the same format as runStep(), without executing a command.
 */
function pmssLogStatus(string $status, string $description, int $rc = 0, ?float $duration = null): void
{
    $dur = $duration ?? 0.0;
    $statusUpper = strtoupper($status);
    logMessage(sprintf('[%s %.3fs rc=%d] %s', $statusUpper, $dur, $rc, $description));
    pmssRecordProfile([
        'description'    => $description,
        'command'        => '',
        'status'         => $statusUpper,
        'rc'             => $rc,
        'duration'       => round($dur, 4),
        'dry_run'        => false,
        'stdout_excerpt' => '',
        'stderr_excerpt' => '',
    ]);
}
