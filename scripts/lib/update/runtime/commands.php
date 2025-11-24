<?php
/**
 * Command execution helpers for update workflows.
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/profile.php';
require_once __DIR__.'/../../runtime.php';

if (!function_exists('runStep')) {
    /**
     * Execute a shell command, keeping failures soft.
     *
     * Logged `rc=<n>` values surface the raw shell return code so operators can
     * read `rc=0` as success and treat any non-zero value as a soft failure.
     */
    function runStep(string $description, string $command): int
    {
        pmssInitProfileStore();
        $dryRun  = getenv('PMSS_DRY_RUN') === '1';
        $started = microtime(true);
        $rc      = $dryRun ? 0 : runCommand($command, false);

        $duration    = microtime(true) - $started;
        // rc reflects the shell return code so downstream logs can inspect rc=0 for success
        $status      = $dryRun ? 'SKIP' : ($rc === 0 ? 'OK' : 'ERR');
        $lastOutput  = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? ['stdout' => '', 'stderr' => ''];
        $stdout      = $dryRun ? '' : ($lastOutput['stdout'] ?? '');
        $stderr      = $dryRun ? '' : ($lastOutput['stderr'] ?? '');
        $stderrShort = $stderr !== '' ? preg_replace('/\s+/', ' ', trim(substr($stderr, 0, 300))) : '';
        $stdoutShort = $stdout !== '' ? preg_replace('/\s+/', ' ', trim(substr($stdout, 0, 300))) : '';

        // ANSI colors
        $cReset  = "\033[0m";
        $cRed    = "\033[31m";
        $cGreen  = "\033[32m";
        $cYellow = "\033[33m";
        $cCyan   = "\033[36m";

        $color = $cGreen;
        if ($status === 'ERR') $color = $cRed;
        if ($status === 'SKIP') $color = $cYellow;

        // Colorize the status block: [STATUS ... rc=N]
        $statusBlock = sprintf('[%s%s%s %.3fs rc=%s%d%s]', 
            $color, $status, $cReset, 
            $duration, 
            ($rc === 0 ? $cGreen : $cRed), $rc, $cReset
        );

        $message = sprintf('%s %s :: %s', $statusBlock, $description, $command);
        if ($status === 'ERR' && $stderrShort !== '') {
            $message .= ' :: ' . $cRed . $stderrShort . $cReset;
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

if (!function_exists('runStepSequence')) {
    /**
     * Execute multiple commands under a shared description banner.
     */
    function runStepSequence(string $description, array $commands): void
    {
        logMessage($description);
        foreach ($commands as $cmd) {
            runStep($description, $cmd);
        }
    }
}

if (!function_exists('aptCmd')) {
    /**
     * Compose a reusable apt-get command prefix.
     */
    function aptCmd(string $args): string
    {
        return 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none '
            .'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold '
            .$args;
    }
}

if (!function_exists('pmssBuildCommand')) {
    /**
     * Build a shell-safe command string from a binary and arguments.
     * Uses escapeshellarg on each argument; intended for simple argv-style
     * commands without shell metacharacter features.
     */
    function pmssBuildCommand(string $program, array $args = []): string
    {
        $prog = escapeshellcmd($program);
        if (empty($args)) {
            return $prog;
        }
        $escaped = array_map(static function ($a) { return escapeshellarg((string)$a); }, $args);
        return $prog.' '.implode(' ', $escaped);
    }
}

if (!function_exists('runStepCmd')) {
    /**
     * Execute a command composed from argv parts with safe quoting under runStep().
     */
    function runStepCmd(string $description, string $program, array $args): int
    {
        return runStep($description, pmssBuildCommand($program, $args));
    }
}

if (!function_exists('pmssLogStatus')) {
    /**
     * Log a status line with duration/rc in the same format as runStep(), without executing a command.
     */
    function pmssLogStatus(string $status, string $description, int $rc = 0, ?float $duration = null): void
    {
        pmssInitProfileStore();
        $dur = $duration !== null ? $duration : 0.0;
        $message  = sprintf('[%s %.3fs rc=%d] %s', strtoupper($status), $dur, $rc, $description);
        logMessage($message);
        pmssRecordProfile([
            'description'    => $description,
            'command'        => '',
            'status'         => strtoupper($status),
            'rc'             => $rc,
            'duration'       => round($dur, 4),
            'dry_run'        => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);
    }
}
