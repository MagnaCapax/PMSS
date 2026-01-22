<?php
/**
 * User transfer helper (library).
 *
 * Provides the implementation behind `scripts/util/userTransfer.php`.
 * The CLI entry point stays thin while the reusable logic lives here so it can
 * be tested hermetically and kept readable.
 *
 * @author Aleksi Ursin
 * @copyright NuCode 2015-2025 - All Rights reserved.
 * @since 31/03/2015
 * @version 2.0
 **/

require_once __DIR__.'/userLifecycle.php';
require_once __DIR__.'/update/runtime/commands.php';

/**
 * Return the CLI usage text.
 */
function pmssUserTransferUsage(): string
{
    return <<<TXT
Usage:
  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_HOSTNAME
  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_USERNAME REMOTE_HOSTNAME

Options:
  --main-passes N     Number of passes for the main rsync (default 31)
  --final-passes N    Number of passes for the final rsync (default 3)
  --sleep-min N       Minimum sleep seconds between passes (default 60)
  --sleep-max N       Maximum sleep seconds between passes (default 360)
  --no-sleep          Disable sleeping between passes
  --dry-run           Log planned steps without executing commands
  --print-password    Print the supplied password at the end (unsafe)
  --help, -h          Show this help

Notes:
  - If REMOTE_HOSTNAME does not contain a dot, ".pulsedmedia.com" is appended.
  - Password can be provided via env: PMSS_USER_TRANSFER_PASSWORD

TXT;
}

/**
 * Validate a hostname (or IPv4 address) without permitting shell metacharacters.
 */
function pmssUserTransferHostnameIsValid(string $hostname): bool
{
    if ($hostname === '') {
        return false;
    }
    if (preg_match('/\\s/', $hostname)) {
        return false;
    }
    if (strlen($hostname) > 253) {
        return false;
    }

    // Accept IPv4 literals to support direct node IP transfers.
    if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return true;
    }

    // Allow hostname labels separated by dots.
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $hostname)) {
        return false;
    }
    if (strpos($hostname, '..') !== false) {
        return false;
    }
    if ($hostname[0] === '.' || substr($hostname, -1) === '.') {
        return false;
    }

    $labels = explode('.', $hostname);
    foreach ($labels as $label) {
        if ($label === '' || strlen($label) > 63) {
            return false;
        }
        if ($label[0] === '-' || substr($label, -1) === '-') {
            return false;
        }
    }
    return true;
}

/**
 * Parse argv and return a normalised configuration array.
 *
 * @throws RuntimeException on invalid input.
 */
function pmssUserTransferParseCli(array $argv): array
{
    // Parse options manually: optionParser treats long flags as value-taking when
    // followed by a positional token, which makes boolean flags fragile.
    $tokens = array_slice($argv, 1);
    $positionals = [];

    $mainPasses = 31;
    $finalPasses = 3;
    $sleepMin = 60;
    $sleepMax = 360;
    $noSleep = false;
    $dryRun = false;
    $printPassword = false;
    $help = false;

    $parseInt = static function (string $name, ?string $value): int {
        if ($value === null || $value === '') {
            throw new RuntimeException('Option --'.$name.' requires a value', 1);
        }
        if (!ctype_digit($value)) {
            throw new RuntimeException('Invalid value for --'.$name.' (expected integer)', 1);
        }
        return (int) $value;
    };

    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        if ($token === '--') {
            $positionals = array_merge($positionals, array_slice($tokens, $i + 1));
            break;
        }

        if (substr($token, 0, 2) === '--') {
            $body = substr($token, 2);
            if ($body === '') {
                continue;
            }
            $key = $body;
            $value = null;
            if (strpos($body, '=') !== false) {
                [$key, $value] = explode('=', $body, 2);
            }

            if ($key === 'help') {
                $help = true;
                continue;
            }
            if ($key === 'no-sleep') {
                $noSleep = true;
                continue;
            }
            if ($key === 'dry-run') {
                $dryRun = true;
                continue;
            }
            if ($key === 'print-password') {
                $printPassword = true;
                continue;
            }

            if ($value === null) {
                $i++;
                $value = $tokens[$i] ?? null;
            }

            if ($key === 'main-passes') {
                $mainPasses = $parseInt('main-passes', $value);
                continue;
            }
            if ($key === 'final-passes') {
                $finalPasses = $parseInt('final-passes', $value);
                continue;
            }
            if ($key === 'sleep-min') {
                $sleepMin = $parseInt('sleep-min', $value);
                continue;
            }
            if ($key === 'sleep-max') {
                $sleepMax = $parseInt('sleep-max', $value);
                continue;
            }

            throw new RuntimeException('Unknown option: --'.$key, 1);
        }

        if (substr($token, 0, 1) === '-' && strlen($token) > 1) {
            $flags = substr($token, 1);
            if ($flags === 'h') {
                $help = true;
                continue;
            }
            throw new RuntimeException('Unknown option: '.$token, 1);
        }

        $positionals[] = $token;
    }

    if ($help) {
        throw new RuntimeException(pmssUserTransferUsage(), 0);
    }

    if (count($positionals) !== 2 && count($positionals) !== 3) {
        throw new RuntimeException('Need arguments.'.PHP_EOL.pmssUserTransferUsage(), 1);
    }

    $localUser = strtolower(trim((string) $positionals[0]));
    $remoteUser = $localUser;
    $hostname = '';
    if (count($positionals) === 2) {
        $hostname = trim((string) $positionals[1]);
    } else {
        $remoteUser = strtolower(trim((string) $positionals[1]));
        $hostname = trim((string) $positionals[2]);
    }

    // Usernames are used in file paths and ssh user arguments; keep strict.
    if (!pmssValidateUsername($localUser) || !pmssValidateUsername($remoteUser)) {
        throw new RuntimeException('Invalid username; expected /^[a-z][a-z0-9]{0,7}$/', 1);
    }

    $suffixAppended = false;
    if ($hostname !== '' && strpos($hostname, '.') === false) {
        $hostname .= '.pulsedmedia.com';
        $suffixAppended = true;
    }
    if (!pmssUserTransferHostnameIsValid($hostname)) {
        throw new RuntimeException('Invalid hostname', 1);
    }

    if ($mainPasses < 1 || $mainPasses > 500) {
        throw new RuntimeException('Invalid --main-passes (expected 1..500)', 1);
    }
    if ($finalPasses < 1 || $finalPasses > 100) {
        throw new RuntimeException('Invalid --final-passes (expected 1..100)', 1);
    }
    if ($sleepMin < 0 || $sleepMax < 0) {
        throw new RuntimeException('Invalid sleep values (expected non-negative integers)', 1);
    }
    if ($sleepMax < $sleepMin) {
        throw new RuntimeException('Invalid sleep range (sleep-max must be >= sleep-min)', 1);
    }
    if ($noSleep) {
        $sleepMin = 0;
        $sleepMax = 0;
    }

    return [
        'localUser' => $localUser,
        'remoteUser' => $remoteUser,
        'hostname' => $hostname,
        'suffixAppended' => $suffixAppended,
        'mainPasses' => $mainPasses,
        'finalPasses' => $finalPasses,
        'sleepMin' => $sleepMin,
        'sleepMax' => $sleepMax,
        'dryRun' => $dryRun,
        'printPassword' => $printPassword,
    ];
}

/**
 * Ensure the caller is root (best effort; depends on posix extension).
 */
function pmssUserTransferAssertRoot(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        throw new RuntimeException('This script must be run as root', 1);
    }
}

/**
 * Fetch passwd metadata for a local user.
 */
function pmssUserTransferPasswdRecord(string $user): ?array
{
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($user);
        if (!is_array($pw)) {
            return null;
        }
        return [
            'uid' => (int) ($pw['uid'] ?? -1),
            'gid' => (int) ($pw['gid'] ?? -1),
            'dir' => (string) ($pw['dir'] ?? ''),
        ];
    }

    $lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return null;
    }
    $prefix = $user.':';
    foreach ($lines as $line) {
        if (strpos($line, $prefix) !== 0) {
            continue;
        }
        $parts = explode(':', $line);
        if (count($parts) < 7) {
            return null;
        }
        return [
            'uid' => (int) $parts[2],
            'gid' => (int) $parts[3],
            'dir' => (string) $parts[5],
        ];
    }
    return null;
}

/**
 * Return the configured home root for user transfers.
 */
function pmssUserTransferHomeRoot(): string
{
    return pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
}

/**
 * Ensure the local home exists, is not a symlink, and matches passwd metadata.
 */
function pmssUserTransferAssertSafeLocalHome(string $user): string
{
    $homeRoot = pmssUserTransferHomeRoot();
    $expected = $homeRoot.'/'.$user;

    $real = realpath($expected);
    if ($real === false || $real !== $expected || !is_dir($expected) || is_link($expected)) {
        throw new RuntimeException('Local user home does not look safe: '.$expected, 1);
    }

    $pw = pmssUserTransferPasswdRecord($user);
    if ($pw === null) {
        throw new RuntimeException('Local user not present in /etc/passwd: '.$user, 1);
    }
    if (($pw['uid'] ?? -1) < 1000) {
        throw new RuntimeException('Refusing to transfer system user: '.$user, 1);
    }
    $pwHome = rtrim((string) ($pw['dir'] ?? ''), '/');
    if ($pwHome !== $expected) {
        throw new RuntimeException('Local user home mismatch for '.$user.' (expected '.$expected.'; passwd has '.$pwHome.')', 1);
    }

    return $expected;
}

/**
 * Read a password from env or from an interactive TTY prompt.
 */
function pmssUserTransferReadPassword(): string
{
    $fromEnv = getenv('PMSS_USER_TRANSFER_PASSWORD');
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }

    $isTty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if (!$isTty) {
        throw new RuntimeException('Password missing (set PMSS_USER_TRANSFER_PASSWORD for non-interactive runs)', 1);
    }

    // Avoid echoing the password on the console.
    $mode = trim((string) @shell_exec('stty -g 2>/dev/null'));
    $pass1 = '';
    $pass2 = '';
    try {
        @shell_exec('stty -echo 2>/dev/null');
        echo 'Remote user password: ';
        $pass1 = (string) fgets(STDIN);
        echo PHP_EOL.'Re-type password: ';
        $pass2 = (string) fgets(STDIN);
        echo PHP_EOL;
    } finally {
        if ($mode !== '') {
            @shell_exec('stty '.escapeshellarg($mode).' 2>/dev/null');
        } else {
            @shell_exec('stty echo 2>/dev/null');
        }
    }

    $pass1 = trim($pass1);
    $pass2 = trim($pass2);
    if ($pass1 === '' || $pass1 !== $pass2) {
        throw new RuntimeException('Password mismatch', 1);
    }
    return $pass1;
}

/**
 * Create a private scratch directory under /root for temporary scripts.
 */
function pmssUserTransferScratchDir(): string
{
    $token = '';
    try {
        $token = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $token = sha1(microtime(true).'-'.mt_rand());
    }
    $dir = '/root/pmss-userTransfer-'.$token;
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create scratch directory: '.$dir, 1);
    }
    @chmod($dir, 0700);
    return $dir;
}

/**
 * Write a file with the given contents and permissions.
 */
function pmssUserTransferWriteFile(string $path, string $contents, int $mode): void
{
    if (@file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Failed writing: '.$path, 1);
    }
    @chmod($path, $mode);
}

/**
 * Build the bash script that performs the main rsync pull (excluding volatile paths).
 */
function pmssUserTransferBuildRsyncMain(array $cfg): string
{
    $remoteUser = $cfg['remoteUser'];
    $hostname = $cfg['hostname'];
    $localUser = $cfg['localUser'];

    // Keep the exclude list in a stable order for readability and diffing.
    $excludes = [
        '.rtorrent.rc',
        '.config/qBittorrent/qBittorrent.conf',
        '.config/deluge/core.conf',
        '.config/deluge/web.conf',
        '.cache',
        'www',
        'session',
        'www/rutorrent/share',
        '.lighttpd',
        '.logs',
        '.local',
        '.lighttpd.conf',
        '.quota',
        '.rtorrentExecuteRun',
        '.trafficData',
        '.trafficDataLocal',
        'rTorrentLog',
        '.bonusQuota',
        '.billingId',
        '.trafficLimit',
    ];

    $excludeArgs = [];
    foreach ($excludes as $item) {
        $excludeArgs[] = '--exclude='.escapeshellarg($item);
    }

    $ssh = sprintf(
        'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l %s',
        escapeshellarg($remoteUser)
    );

    $cmd = 'rsync -av -e '.escapeshellarg($ssh)
        .' '.implode(' ', $excludeArgs)
        .' '.escapeshellarg($remoteUser.'@'.$hostname.':/home/'.$remoteUser.'/')
        .' '.escapeshellarg('/home/'.$localUser.'/');

    return "#!/bin/bash\nset -e\n{$cmd}\n";
}

/**
 * Build the bash script that pulls volatile paths after the main sync.
 */
function pmssUserTransferBuildRsyncFinal(array $cfg): string
{
    $remoteUser = $cfg['remoteUser'];
    $hostname = $cfg['hostname'];
    $localUser = $cfg['localUser'];

    // Keep this list explicit; do not rely on brace expansion inside expect.
    $sources = [
        '/home/'.$remoteUser.'/session',
        '/home/'.$remoteUser.'/www/rutorrent/share',
        '/home/'.$remoteUser.'/.lighttpd/custom',
        '/home/'.$remoteUser.'/.lighttpd/custom.d',
        '/home/'.$remoteUser.'/.local',
        '/home/'.$remoteUser.'/www/public',
    ];

    $ssh = sprintf(
        'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l %s',
        escapeshellarg($remoteUser)
    );

    $args = [];
    foreach ($sources as $source) {
        $args[] = escapeshellarg($remoteUser.'@'.$hostname.':'.$source);
    }

    $cmd = 'rsync -av -e '.escapeshellarg($ssh)
        .' '.implode(' ', $args)
        .' '.escapeshellarg('/home/'.$localUser.'/');

    return "#!/bin/bash\nset -e\n{$cmd}\n";
}

/**
 * Build a minimal Expect wrapper that injects the password via env and propagates exit codes.
 */
function pmssUserTransferBuildExpectWrapper(): string
{
    return <<<'EXP'
#!/usr/bin/expect -f
set timeout -1

if {[llength $argv] != 1} {
    puts stderr "Usage: transfer.expect <command-path>"
    exit 2
}

if {![info exists env(PMSS_USER_TRANSFER_PASSWORD)]} {
    puts stderr "Missing env PMSS_USER_TRANSFER_PASSWORD"
    exit 2
}

set password $env(PMSS_USER_TRANSFER_PASSWORD)
set cmd [lindex $argv 0]

spawn -noecho $cmd
expect {
    -re "(?i)assword:" {
        send -- "$password\r"
        exp_continue
    }
    eof
}

set result [wait]
exit [lindex $result 3]
EXP;
}

/**
 * Sleep between passes (optionally randomised) while logging the reason.
 */
function pmssUserTransferSleep(int $min, int $max, string $reason): void
{
    // Dry runs should never stall for long-running sleeps.
    if (getenv('PMSS_DRY_RUN') === '1') {
        return;
    }
    if ($max <= 0) {
        return;
    }

    $seconds = $min;
    if ($max > $min) {
        try {
            $seconds = random_int($min, $max);
        } catch (Throwable $e) {
            $seconds = rand($min, $max);
        }
    }

    logMessage(sprintf('[SLEEP] %s (%ds)', $reason, $seconds));
    sleep($seconds);
}

/**
 * Return true when the resolved path lives under the resolved home directory.
 */
function pmssUserTransferIsPathWithinHome(string $path, string $home): bool
{
    $realHome = realpath($home);
    if ($realHome === false) {
        return false;
    }
    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }
    $prefix = rtrim($realHome, '/').'/';
    return strpos(rtrim($realPath, '/').'/', $prefix) === 0;
}

/**
 * Apply post-transfer steps (rename ruTorrent user dir, normalise permissions, restart marker).
 */
function pmssUserTransferPostSetup(array $cfg, string $home): void
{
    $localUser = $cfg['localUser'];
    $remoteUser = $cfg['remoteUser'];

    // Rename ruTorrent user directory when remote/local user differs.
    if ($remoteUser !== $localUser) {
        $src = $home.'/www/rutorrent/share/users/'.$remoteUser;
        $dst = $home.'/www/rutorrent/share/users/'.$localUser;
        if (file_exists($src) && !file_exists($dst)) {
            $srcSafe = pmssUserTransferIsPathWithinHome($src, $home);
            $dstParentSafe = pmssUserTransferIsPathWithinHome(dirname($dst), $home);
            if ($srcSafe && $dstParentSafe) {
                if (!@rename($src, $dst)) {
                    runStep(
                        'Renaming ruTorrent user directory',
                        pmssBuildCommand('mv', [$src, $dst])
                    );
                } else {
                    logMessage('[OK] Renamed ruTorrent user directory');
                }
            } else {
                logMessage('[WARN] Skipping ruTorrent rename (path escapes home)');
            }
        }
    }

    // Normalise ownership/permissions via the shared helper, which avoids unsafe
    // recursive chown dereferencing symlinks into the host filesystem.
    runStep(
        'Normalising user permissions',
        pmssBuildCommand('php', [__DIR__.'/../util/userPermissions.php', $localUser])
    );

    // Request rTorrent restart (best effort) so migrated data is picked up.
    $wwwDir = $home.'/www';
    if (is_dir($wwwDir) && !is_link($wwwDir) && pmssUserTransferIsPathWithinHome($wwwDir, $home)) {
        $marker = $wwwDir.'/.rtorrentRestart';
        runStep('Requesting rTorrent restart marker', pmssBuildCommand('touch', [$marker]));
        runStep('Setting rTorrent restart marker owner', pmssBuildCommand('chown', [$localUser.':'.$localUser, $marker]));
    } else {
        logMessage('[WARN] Skipping rTorrent restart marker (www dir missing or unsafe)');
    }
}

/**
 * Entry point used by scripts/util/userTransfer.php.
 */
function pmssUserTransferMain(array $argv): int
{
    try {
        pmssUserTransferAssertRoot();
        $cfg = pmssUserTransferParseCli($argv);
        $home = pmssUserTransferAssertSafeLocalHome($cfg['localUser']);

        if ($cfg['dryRun']) {
            putenv('PMSS_DRY_RUN=1');
        }

        if ($cfg['suffixAppended']) {
            logMessage('[INFO] No dot in hostname, appending .pulsedmedia.com');
        }

        logMessage('[START] User transfer initialised');
        logMessage(sprintf('[INFO] Local user=%s Remote user=%s Host=%s', $cfg['localUser'], $cfg['remoteUser'], $cfg['hostname']));
        logMessage(sprintf('[INFO] Main passes=%d Final passes=%d Sleep=%d..%d', $cfg['mainPasses'], $cfg['finalPasses'], $cfg['sleepMin'], $cfg['sleepMax']));

        // Dry runs should be fully non-interactive and avoid writing temp scripts.
        if ($cfg['dryRun']) {
            $scratch = '/root/pmss-userTransfer-<generated>';
            $expect = $scratch.'/transfer.expect';
            $mainScript = $scratch.'/rsync-main.sh';
            $finalScript = $scratch.'/rsync-final.sh';
            runStep('Pulling home data (pass 1/'.$cfg['mainPasses'].')', pmssBuildCommand($expect, [$mainScript]));
            runStep('Pulling volatile data (pass 1/'.$cfg['finalPasses'].')', pmssBuildCommand($expect, [$finalScript]));
            runStep('Normalising user permissions', pmssBuildCommand('php', [__DIR__.'/../util/userPermissions.php', $cfg['localUser']]));
            logMessage('[SKIP] Dry run complete');
            return 0;
        }

        $password = pmssUserTransferReadPassword();
        putenv('PMSS_USER_TRANSFER_PASSWORD='.$password);

        $scratch = pmssUserTransferScratchDir();
        $paths = [];
        $cleanup = function () use (&$paths, $scratch): void {
            foreach ($paths as $p) {
                if ($p !== '' && file_exists($p)) {
                    @unlink($p);
                }
            }
            if (is_dir($scratch)) {
                @rmdir($scratch);
            }
        };
        register_shutdown_function($cleanup);

        // Generate scripts under /root with restrictive permissions.
        $expect = $scratch.'/transfer.expect';
        $mainScript = $scratch.'/rsync-main.sh';
        $finalScript = $scratch.'/rsync-final.sh';

        $paths = [$expect, $mainScript, $finalScript];
        pmssUserTransferWriteFile($expect, pmssUserTransferBuildExpectWrapper()."\n", 0700);
        pmssUserTransferWriteFile($mainScript, pmssUserTransferBuildRsyncMain($cfg), 0700);
        pmssUserTransferWriteFile($finalScript, pmssUserTransferBuildRsyncFinal($cfg), 0700);

        // Run repeated passes to converge the remote state before the final sync.
        for ($i = 1; $i <= $cfg['mainPasses']; $i++) {
            runStep(
                sprintf('Pulling home data (pass %d/%d)', $i, $cfg['mainPasses']),
                pmssBuildCommand($expect, [$mainScript])
            );
            if ($i < $cfg['mainPasses']) {
                pmssUserTransferSleep($cfg['sleepMin'], $cfg['sleepMax'], 'Waiting before next main pass');
            }
        }

        // Final sync for volatile paths such as session data.
        for ($i = 1; $i <= $cfg['finalPasses']; $i++) {
            runStep(
                sprintf('Pulling volatile data (pass %d/%d)', $i, $cfg['finalPasses']),
                pmssBuildCommand($expect, [$finalScript])
            );
            if ($i < $cfg['finalPasses']) {
                pmssUserTransferSleep($cfg['sleepMin'], $cfg['sleepMax'], 'Waiting before next final pass');
            }
        }

        pmssUserTransferPostSetup($cfg, $home);
        $cleanup();
        putenv('PMSS_USER_TRANSFER_PASSWORD');

        if ($cfg['printPassword']) {
            logMessage('[WARN] Remote password: '.$password);
        }

        logMessage('[OK] User transfer complete');
        return 0;
    } catch (RuntimeException $e) {
        $code = $e->getCode();
        if ($code === 0) {
            echo $e->getMessage();
            return 0;
        }
        fwrite(STDERR, $e->getMessage().PHP_EOL);
        return is_int($code) && $code > 0 ? $code : 1;
    }
}
