<?php
/**
 * Command execution helpers for update workflows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/profile.php';

/**
 * Execute a shell command, keeping failures soft.
 *
 * Logged `rc=<n>` values surface the raw shell return code so operators can
 * read `rc=0` as success. Declared skip codes retain their raw rc and record SKIP.
 */
function runStep(string $description, string $command, array $skipExitCodes = []): int
{
    $dryRun  = pmssEnvFlagEnabled('PMSS_DRY_RUN');
    $started = microtime(true);
    $isTty   = pmssStreamIsTty(STDOUT);
    // Emit a start banner for interactive operators so hangs show which step is running.
    if ($isTty) {
        echo pmssCliAnsiStyle('[START] '.$description, '34').PHP_EOL;
    } else {
        // Persist start markers to the log for non-interactive runs.
        logMessage('[START] '.$description.' :: '.$command);
    }
    $rc      = $dryRun ? 0 : runCommand($command, false, null, false, $skipExitCodes);

    $duration    = microtime(true) - $started;
    // rc reflects the shell return code so downstream logs can inspect rc=0 for success
    $status      = $dryRun || in_array($rc, $skipExitCodes, true) ? 'SKIP' : ($rc === 0 ? 'OK' : 'ERR');
    $lastOutput  = $dryRun ? [] : ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? []);
    $stdout      = $lastOutput['stdout'] ?? '';
    $stderr      = $lastOutput['stderr'] ?? '';
    $stderrShort = pmssLogWhitespaceCollapse(trim(substr($stderr, 0, 300)));
    $stdoutShort = pmssLogWhitespaceCollapse(trim(substr($stdout, 0, 300)));

    // Fork failures may occur inside nested scripts (e.g. find/chown) while the
    // wrapper command itself still exits rc=0. Detect known strings and emit a
    // non-shell diagnostics snapshot so we have the cgroup/rlimit context.
    if (!$dryRun && preg_match('/\b(Cannot fork|fork failed|Unable to fork|Resource temporarily unavailable)\b/i', $stdout."\n".$stderr) === 1) {
        pmssDumpForkDiagnostics('runStep: '.$description.' :: '.$command, 'logMessage');
    }

    // Colorize the status block: [STATUS ... rc=N]
    $statusBlock = sprintf('[%s %.3fs rc=%s]',
        pmssCliAnsiStyle($status, $status === 'ERR' ? '31' : ($status === 'SKIP' ? '33' : '32')),
        $duration, pmssCliAnsiStyle((string) $rc, $rc === 0 ? '32' : '31')
    );

    $message = sprintf('%s %s :: %s', $statusBlock, $description, $command);
    if ($status === 'ERR' && $stderrShort !== '') {
        $message .= ' :: '.pmssCliAnsiStyle($stderrShort, '31');
    }
    // Use structured logger from logging.php to avoid missing logmsg() when
    // this runtime is invoked outside update.php/bootstrap paths.
    logMessage($message);
    pmssCommandProfileRecord($description, $command, $status, $rc, $duration, $dryRun, $stdoutShort, $stderrShort);
    return $rc;
}

if (!function_exists('runUserStep')) {
    /**
     * Run a command while tagging the associated user.
     */
    function runUserStep(string $user, string $description, string $command, array $skipExitCodes = []): int
    {
        $profileCountBefore = is_array($GLOBALS['PMSS_PROFILE'] ?? null)
            ? count($GLOBALS['PMSS_PROFILE'])
            : 0;
        $rc = runStep("[user:$user] {$description}", $command, $skipExitCodes);
        if (is_array($GLOBALS['PMSS_PROFILE'][$profileCountBefore] ?? null)) {
            $GLOBALS['PMSS_PROFILE'][$profileCountBefore]['description'] = $description;
        }

        return $rc;
    }
}

/** Select real account entries before changing mode, skipping multiply linked files. */
function pmssUserEntryChmodCommand(string $path, int $mode, bool $recursive = false): string
{
    if ($mode < 0 || $mode > 07777) {
        throw new InvalidArgumentException('Invalid account entry mode');
    }
    return sprintf(
        'find %s %s-not -type l \( ! -type f -o -links 1 \) -exec chmod %04o {} +',
        escapeshellarg($path),
        $recursive ? '' : '-maxdepth 0 ',
        $mode
    );
}

/**
 * Compose a reusable apt-get command prefix.
 */
function aptCmd(string $args): string
{
    return pmssAptDpkgEnvPrefix().' '
        .'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold '
        .$args;
}

/**
 * Compose a reusable dpkg command prefix with unattended environment vars.
 */
function dpkgCmd(string $args): string
{
    return pmssAptDpkgEnvPrefix().' dpkg '.$args;
}
