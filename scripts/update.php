#!/usr/bin/env php
<?php
/**
 * PMSS Bootstrap Updater
 *
 * Responsibilities:
 *   1. Parse request parameters and determine the snapshot to deploy
 *   2. Fetch the requested tree (git branch/pin or release tarball)
 *   3. Copy scripts/etc/var into place and hand off to update-step2.php
 *
 * ARCHITECTURAL CONSTRAINT (DO NOT REFACTOR):
 * This file MUST remain largely self-contained and monolithic. It is the
 * "break-glass" recovery tool. In catastrophic failure modes (e.g., where
 * the update mechanism itself is broken or deleted), an operator must be
 * able to restore functionality via a simple one-liner like:
 *   `wget -qO- https://.../update.php | php -- --scripts-only`
 *
 * Modularizing this file into multiple dependencies would require manually
 * fetching dozens of files to correct paths during an outage, turning a
 * seconds-long recovery into a 15+ minute manual reconstruction task.
 * Do not extract logic from here into `scripts/lib/` unless it is strictly
 * optional/progressive enhancement.
 *
 * | Flag / Spec        | Purpose |
 * | ------------------ | ------- |
 * | `<spec>`           | Optional version identifier. Accepts `git/<branch>[:YYYY-MM-DD]`, `release[:tag]`, or `main` to reuse the last recorded spec. |
 * | `--dry-run`        | Exercise fetch/staging logic without copying files or running phase 2. Implies JSON/profile logging when configured. |
 * | `--scripts-only`   | Deploy refreshed `/scripts` and `/etc/skel` content, skip `update-step2.php`. Useful for emergency hotfixes and MUST NOT invoke apt/apt-get or any other package manager commands. |
 * | `--repo=<url>`     | Override the git remote used for `git/*` specs. Combined with `--branch` to pin alternate forks. |
 * | `--branch=<name>`  | Branch used with `--repo`. Defaults to `main` when unspecified. |
 * | `--dist-upgrade=<max>` | Run the dist-upgrade helper (from `scripts/lib/update/distUpgrade.php`) to perform a one-step Debian release upgrade capped at the requested maximum, then continue with phase 2. |
 * | `--skip-self-update` | Internal flag injected during self-refresh to avoid recursion; operators should not pass it manually. |
 * | `--help`           | Print usage examples and exit without making changes. |
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

declare(strict_types=1);

// Allow more headroom during large updates but still cap to avoid host-wide OOM.
@ini_set('memory_limit', '4096M');

const DEFAULT_REPO          = 'https://github.com/MagnaCapax/PMSS';
const CURL_UA               = 'PMSS-Updater (+https://pulsedmedia.com)';
const VERSION_DIR           = '/etc/seedbox/config';
const VERSION_FILE          = VERSION_DIR.'/version';
const VERSION_META          = VERSION_DIR.'/version.meta';
const VERSION_RUNTIME_FILE  = '/etc/seedbox/runtime/version';
const JSON_LOG              = '/var/log/pmss-update.jsonl';
const SELF_UPDATE_SKIP_FLAG = '--skip-self-update';
const SCRIPTS_ONLY_FLAG     = '--scripts-only';
const PMSS_CORRELATION_ENV  = 'PMSS_CORRELATION_ID';
define('PMSS_UPDATE_LOCK_FILE', '/var/lib/pmss/update.lock');
define('PMSS_UPDATE_LOCK_ENV', 'PMSS_UPDATE_LOCK_HELD');
const PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS = 30;
const PMSS_UPDATE_LOCK_RETRY_SECONDS = 2;

$GLOBALS['PMSS_CORRELATION_ID_CACHE'] = $GLOBALS['PMSS_CORRELATION_ID_CACHE'] ?? null;

const EXIT_PARSE = 11;
const EXIT_FETCH = 12;
const EXIT_COPY  = 13;
const EXIT_DIST  = 14;

// Best-effort directory creation for bootstrap log/state writes.
function pmssEnsureDirectory(string $dir, int $mode = 0755): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, $mode, true);
    }
}

/**
 * Minimal logger – writes both to stdout and a file so rescue scenarios still log.
 */
function logmsg(string $message): void
{
    static $logFiles = null;
    if ($logFiles === null) {
        $script = $_SERVER['SCRIPT_NAME'] ?? __FILE__;
        $base   = basename($script, '.php');
        $dir    = '/var/log/pmss';
        pmssEnsureDirectory($dir);
        $logFiles = [
            'primary'  => rtrim($dir, '/').'/'.$base.'.log',
            'fallback' => '/tmp/'.$base.'.log',
        ];
    }

    $timestamp = date('[Y-m-d H:i:s] ');
    @file_put_contents($logFiles['primary'], $timestamp.$message.PHP_EOL, FILE_APPEND | LOCK_EX)
 || @file_put_contents($logFiles['fallback'], $timestamp.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $message.PHP_EOL);
}

/**
 * Read or initialize the cross-process correlation ID for this updater run.
 */
function pmssCorrelationId(bool $createIfMissing = true): string
{
    if (is_string($GLOBALS['PMSS_CORRELATION_ID_CACHE']) && $GLOBALS['PMSS_CORRELATION_ID_CACHE'] !== '') {
        return $GLOBALS['PMSS_CORRELATION_ID_CACHE'];
    }

    $envValue = trim((string) (getenv(PMSS_CORRELATION_ENV) ?: ''));
    if ($envValue !== '') {
        $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = $envValue;
        return $envValue;
    }

    if (!$createIfMissing) {
        return '';
    }

    $timestamp = gmdate('Ymd-His');
    $hostRaw = function_exists('gethostname') ? (string) @gethostname() : (string) php_uname('n');
    $host = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $hostRaw)), '-');
    $host = $host !== '' ? $host : 'host';

    try {
        $generated = $timestamp.'-'.$host.'-'.bin2hex(random_bytes(3));
    } catch (\Throwable $throwable) {
        $generated = $timestamp.'-'.$host.'-'.substr(hash('sha256', $timestamp.$host.microtime(true)), 0, 6);
    }

    $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = $generated;
    putenv(PMSS_CORRELATION_ENV.'='.$generated);
    return $generated;
}

function logEvent(string $event, array $payload = []): void
{
    $payload['event'] = $event;
    $payload['ts']    = $payload['ts'] ?? date('c');
    $correlationId = pmssCorrelationId(false);
    if ($correlationId !== '') {
        $payload['pmss_correlation_id'] = $payload['pmss_correlation_id'] ?? $correlationId;
    }

    $dir = dirname(JSON_LOG);
    pmssEnsureDirectory($dir);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        @file_put_contents(JSON_LOG, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod(JSON_LOG, 0640);
    }
}

function fatal(string $message, int $code): void
{
    logmsg('[ERROR] '.$message);
    logEvent('fatal', ['message' => $message, 'code' => $code]);
    exit($code);
}

/**
 * Acquire a global update lock to prevent overlapping runs.
 */
function pmssAcquireUpdateLock(): void
{
    if (getenv(PMSS_UPDATE_LOCK_ENV) === '1') {
        return;
    }

    $dir = dirname(PMSS_UPDATE_LOCK_FILE);
    pmssEnsureDirectory($dir);
    $fh = @fopen(PMSS_UPDATE_LOCK_FILE, 'c');
    if ($fh === false) {
        fatal('Unable to open update lock file: '.PMSS_UPDATE_LOCK_FILE, EXIT_COPY);
    }

    logEvent('update_lock_wait', ['path' => PMSS_UPDATE_LOCK_FILE, 'max_wait_seconds' => PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS]);
    $deadline = microtime(true) + PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS;
    $attempt = 0;
    while (true) {
        if (@flock($fh, LOCK_EX | LOCK_NB)) {
            break;
        }

        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.0) {
            @fclose($fh);
            logmsg('[WARN] Update lock busy after '.PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS.'s; skipping this run');
            logEvent('update_lock_busy_skip', [
                'path' => PMSS_UPDATE_LOCK_FILE,
                'wait_seconds' => PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS,
            ]);
            exit(0);
        }

        $attempt++;
        $sleepSeconds = min(PMSS_UPDATE_LOCK_RETRY_SECONDS, max(1, (int) ceil($remaining)));
        logEvent('update_lock_busy', [
            'path' => PMSS_UPDATE_LOCK_FILE,
            'attempt' => $attempt,
            'retry_seconds' => $sleepSeconds,
        ]);
        sleep($sleepSeconds);
    }

    $GLOBALS['PMSS_UPDATE_LOCK_HANDLE'] = $fh;
    putenv(PMSS_UPDATE_LOCK_ENV.'=1');
    pmssCorrelationId();
    logEvent('update_lock_acquired', ['path' => PMSS_UPDATE_LOCK_FILE]);

    register_shutdown_function('pmssReleaseUpdateLock');
}

/**
 * Release the global update lock.
 */
function pmssReleaseUpdateLock(): void
{
    static $released = false;

    if ($released) {
        return;
    }
    if (getenv(PMSS_UPDATE_LOCK_ENV) !== '1') {
        return;
    }

    if (isset($GLOBALS['PMSS_UPDATE_LOCK_HANDLE'])) {
        if (is_resource($GLOBALS['PMSS_UPDATE_LOCK_HANDLE'])) {
            @flock($GLOBALS['PMSS_UPDATE_LOCK_HANDLE'], LOCK_UN);
            @fclose($GLOBALS['PMSS_UPDATE_LOCK_HANDLE']);
        }
        unset($GLOBALS['PMSS_UPDATE_LOCK_HANDLE']);
    }
    putenv(PMSS_UPDATE_LOCK_ENV);
    $released = true;
    logEvent('update_lock_released', ['path' => PMSS_UPDATE_LOCK_FILE]);
}

/**
 * Make sure /etc/apt/sources.list matches the detected suite before any apt command runs.
 */
function normalizeAptSources(): void
{
    $codename = '';
    $major    = '';

    if (is_readable('/etc/os-release')) {
        $data = parse_ini_file('/etc/os-release');
        if ($data !== false) {
            $codename = $data['VERSION_CODENAME'] ?? '';
            $version  = $data['VERSION_ID'] ?? '';
            if ($version !== '') {
                $major = explode('.', $version)[0];
            }
        }
    }

    if ($codename === '' && is_readable('/etc/debian_version')) {
        $version = trim((string) @file_get_contents('/etc/debian_version'));
        if ($version !== '') {
            $major = explode('.', $version)[0];
            $codenameByMajor = ['10' => 'buster', '11' => 'bullseye', '12' => 'bookworm', '13' => 'trixie'];
            $codename = $codenameByMajor[$major] ?? $codename;
        }
    }

    logmsg(sprintf('[INFO] Detected distro codename=%s major=%s', $codename !== '' ? $codename : 'unknown', $major !== '' ? $major : 'unknown'));

    if ($codename === '' || $codename === 'buster') {
        return;
    }

    // NOTE: Base Debian repos use sources.list templates, NOT deb822. See @docs/adr/0008-reject-deb822-apt-sources-migration.md
    $sourcesPath = '/etc/apt/sources.list';
    $current     = @file_get_contents($sourcesPath) ?: '';

    if ($current !== '' && strpos($current, $codename) !== false) {
        return;
    }

    if ($current !== '' && strpos($current, 'buster') === false) {
        // Custom/non-buster sources present; leave untouched to avoid clobbering operator config.
        logmsg('[INFO] Existing sources.list uses a non-buster custom suite; leaving unchanged');
        return;
    }

    $components = 'main contrib non-free';
    if ((int) $major >= 12) {
        $components .= ' non-free-firmware';
    }

    $content = "deb http://deb.debian.org/debian {$codename} {$components}\n";
    $content .= "deb http://security.debian.org/debian-security {$codename}-security {$components}\n";
    $content .= "deb http://deb.debian.org/debian {$codename}-updates {$components}\n";

    if (pmssWriteBootstrapFile($sourcesPath, $content, 'apt sources list', 0644, LOCK_EX)) {
        logmsg("[INFO] Rewrote sources.list for suite {$codename}");
    }

    $extraLists = glob('/etc/apt/sources.list.d/*.list') ?: [];
    foreach ($extraLists as $list) {
        $data = @file_get_contents($list);
        if ($data === false) {
            continue;
        }
        // Only act on active (non-comment) buster entries; otherwise we'd rewrite the
        // same file on every update and spam the system with stale backup artifacts.
        if (preg_match('/^[ \t]*[^#\r\n].*\\bbuster\\b/im', $data) === 1) {
            $mutated = preg_replace('/^([^#].*)/m', '# PMSS(suite-mismatch): disabled: $1', $data);
            if ($mutated !== null && $mutated !== $data) {
                if (pmssWriteBootstrapFile($list, $mutated, 'stale apt source list', null, LOCK_EX)) {
                    logmsg('[INFO] Disabled stale buster entry in '.basename($list));
                }
            }
        }
    }
}

function ensureRoot(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fatal('This updater must be run as root.', EXIT_COPY);
    }
}

function updateUsage(string $script): void
{
    echo "Usage: {$script} [<spec>] [--repo=<url>] [--branch=<name>] [--dry-run] [--dist-upgrade=<max>] [--scripts-only]\n";
    echo "Examples:\n";
    echo "  {$script}                      # update from git/main (default repo)\n";
    echo "  {$script} git/dev:2025-01-03   # dev branch pinned to a date\n";
    echo "  {$script} release:2025-07-12   # explicit tagged release\n";
    echo "  {$script} --repo=https://git/url.git --branch=beta\n";
    echo "  {$script} --dist-upgrade=11         # dist-upgrade one major step, capped at Debian 11\n";
    echo "  {$script} --scripts-only            # refresh scripts/skel only; never runs apt/apt-get\n";
}

/**
 * Parse CLI arguments for the bootstrap updater.
 *
 * Inputs:
 *  - argv array from PHP entrypoint (index 0 is the script path)
 *  - Accepts: <spec>, --dry-run, --dist-upgrade=<max>, --scripts-only,
 *             --repo=<url>, --branch=<name>, and internal --skip-self-update
 * Behavior:
 *  - Synthesizes `spec` when --repo/--branch are supplied
 *  - Defaults spec to storedSpec() or 'git/main' when omitted
 * Output: associative array with keys:
 *  - dry_run(bool), dist_upgrade(bool|string), scripts_only(bool),
 *    skip_self_update(bool), spec(string), repo(?string), branch(?string)
 */
function parseArguments(array $argv): array
{
    $options = [
        'dry_run'         => false,
        'dist_upgrade'    => false,
        'skip_self_update'=> false,
        'scripts_only'    => false,
        'spec'            => '',
        'repo'            => null,
        'branch'          => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            updateUsage($argv[0]);
            exit(0);
        }
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if (strncmp($arg, '--dist-upgrade=', 15) === 0) {
            $options['dist_upgrade'] = substr($arg, 15);
            continue;
        }
        if ($arg === '--dist-upgrade') {
            $options['dist_upgrade'] = true;
            continue;
        }
        if ($arg === SELF_UPDATE_SKIP_FLAG) {
            $options['skip_self_update'] = true;
            continue;
        }
        if ($arg === SCRIPTS_ONLY_FLAG || $arg === '--scriptonly') {
            $options['scripts_only'] = true;
            continue;
        }
        if (strncmp($arg, '--repo=', 7) === 0) {
            $options['repo'] = trim(substr($arg, 7));
            continue;
        }
        if (strncmp($arg, '--branch=', 9) === 0) {
            $options['branch'] = trim(substr($arg, 9));
            continue;
        }
        if ($options['spec'] === '') {
            $options['spec'] = $arg;
        }
    }

    if (($options['repo'] ?? '') !== '' || ($options['branch'] ?? '') !== '') {
        $repo   = $options['repo']   !== null && $options['repo']   !== '' ? $options['repo']   : DEFAULT_REPO;
        $branch = $options['branch'] !== null && $options['branch'] !== '' ? $options['branch'] : 'main';
        $options['spec'] = 'git/'.$repo.':'.$branch;
    }

    if ($options['spec'] === '') {
        $stored = storedSpec();
        $options['spec'] = $stored !== '' ? $stored : 'git/main';
    }

    return $options;
}

function storedSpec(): string
{
    if (!file_exists(VERSION_FILE)) {
        return '';
    }
    $raw = trim((string)@file_get_contents(VERSION_FILE));
    if ($raw === '') {
        return '';
    }
    if (($pos = strpos($raw, '@')) !== false) {
        $raw = substr($raw, 0, $pos);
    }
    return trim($raw);
}

/**
 * Normalize a user-provided version spec into canonical form.
 *
 * Supports: 'git/<branch>[:YYYY-MM-DD]', 'release[:tag]', 'git <branch>',
 * bare branch names, and URLs. Returns '' for invalid/unsupported input.
 */
function normaliseSpec(string $spec): string
{
    $spec = trim($spec);
    if ($spec === '') {
        return '';
    }
    if (preg_match('/^(git|release)([\/:]).+/i', $spec)) {
        return $spec;
    }
    if (preg_match('/^git\s+(.*)$/i', $spec, $m)) {
        $rest = str_replace(' ', '', $m[1]);
        return $rest === '' ? 'git/main' : 'git/'.$rest;
    }
    if (preg_match('/^release\s*(.*)$/i', $spec, $m)) {
        $rest = trim($m[1]);
        return $rest === '' || $rest === ':' || $rest === '/' ? 'release' : 'release:'.$rest;
    }
    if (preg_match('#^(https?|ssh)://#', $spec)) {
        return 'git/'.$spec;
    }
    if (preg_match('/^[a-zA-Z0-9._\-]+$/', $spec)) {
        return 'git/'.$spec;
    }
    return '';
}

/**
 * Parse a normalized spec into components.
 *
 * Input must start with 'git/' or 'release:' or 'release/'. On success returns
 * ['type' => 'git'|'release', 'repo' => string, 'branch' => string, 'pin' => string].
 * On failure calls fatal(EXIT_PARSE).
 */
function parseSpec(string $spec): array
{
    $spec = trim($spec);
    if (strcasecmp($spec, 'release') === 0 || preg_match('/^release[\/:]$/i', $spec) === 1) {
        return [
            'type'   => 'release',
            'repo'   => DEFAULT_REPO,
            'branch' => '',
            'pin'    => '',
        ];
    }

    if (!preg_match('/^(git|release)([\/:])(.*)$/i', $spec, $m)) {
        fatal("Unable to parse source spec '{$spec}'", EXIT_PARSE);
    }

    $type = strtolower($m[1]);
    $rest = $m[3];

    if ($type === 'release') {
        return [
            'type'   => 'release',
            'repo'   => DEFAULT_REPO,
            'branch' => '',
            'pin'    => ltrim($rest, ':'),
        ];
    }

    $repo   = DEFAULT_REPO;
    $branch = 'main';
    $pin    = '';

    if (preg_match('/:(\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?)$/', $rest, $match)) {
        $pin  = $match[1];
        $rest = substr($rest, 0, -strlen($match[0]));
    }

    if ($rest === '') {
        // nothing more to do
    } elseif (preg_match('#^(https?|ssh)://#', $rest)) {
        $pos = strrpos($rest, ':');
        if ($pos !== false && $pos > 8) {
            $repo   = substr($rest, 0, $pos);
            $branch = substr($rest, $pos + 1) ?: 'main';
        } else {
            $repo = rtrim($rest, '/');
        }
    } elseif (strpos($rest, ':') !== false) {
        [$maybeRepo, $maybeBranch] = explode(':', $rest, 2);
        if (preg_match('#://|/#', $maybeRepo)) {
            $repo   = $maybeRepo;
            $branch = $maybeBranch !== '' ? $maybeBranch : 'main';
        } else {
            $branch = $maybeBranch !== '' ? $maybeBranch : 'main';
        }
    } else {
        $branch = $rest;
    }

    return [
        'type'   => 'git',
        'repo'   => $repo,
        'branch' => $branch,
        'pin'    => $pin,
    ];
}

function createWorkdir(): string
{
    $base = sys_get_temp_dir().'/pmss-update-'.bin2hex(random_bytes(4));
    if (!@mkdir($base, 0700, true)) {
        fatal("Unable to create temporary directory {$base}", EXIT_FETCH);
    }
    return $base;
}

function resolveLatestRelease(): string
{
    $ctx = stream_context_create(['http' => ['user_agent' => CURL_UA, 'timeout' => 10]]);
    $json = @file_get_contents('https://api.github.com/repos/MagnaCapax/PMSS/releases/latest', false, $ctx);
    if ($json === false) {
        fatal('Unable to query GitHub for the latest release tag.', EXIT_FETCH);
    }
    $data = json_decode($json, true);
    $tag  = is_array($data) ? (string)($data['tag_name'] ?? '') : '';
    if ($tag === '') {
        fatal('GitHub API did not return a tag_name for the latest release.', EXIT_FETCH);
    }
    return $tag;
}

/**
 * Fetch the requested snapshot into a temporary directory.
 *
 * For 'release': downloads the GitHub tarball for the tag (or latest) and
 * extracts into `$tmp`. For 'git': shallow clones the branch into `$tmp` and
 * optionally checks out `<branch>@{<pin>}` if a date pin is provided.
 * Fatal on failure.
 */
function fetchSnapshot(array $spec, string $tmp): void
{
    if ($spec['type'] === 'release') {
        $tag = $spec['pin'] !== '' ? $spec['pin'] : resolveLatestRelease();
        $tar = $tmp.'/source.tgz';
        $url = 'https://api.github.com/repos/MagnaCapax/PMSS/tarball/'.rawurlencode($tag);
        $cmd = sprintf(
            'curl -sfL -A %s %s -o %s',
            escapeshellarg(CURL_UA),
            escapeshellarg($url),
            escapeshellarg($tar)
        );
        pmssRunBootstrapCommand($cmd, EXIT_FETCH);
        pmssRunBootstrapCommand('tar -xzf '.escapeshellarg($tar).' -C '.escapeshellarg($tmp).' --strip-components=1', EXIT_FETCH);
        return;
    }

    $clone = sprintf(
        'git clone --quiet --depth=1 --branch %s %s %s',
        escapeshellarg($spec['branch']),
        escapeshellarg($spec['repo']),
        escapeshellarg($tmp)
    );
    pmssRunBootstrapCommand($clone, EXIT_FETCH);

    if ($spec['pin'] !== '') {
        $rev = escapeshellarg($spec['branch'].'@{'.$spec['pin'].'}');
        pmssRunBootstrapCommand('cd '.escapeshellarg($tmp).' && git fetch --quiet && git checkout '.$rev, EXIT_FETCH);
    }
}

function pmssRunBootstrapCommand(string $command, ?int $fatalCode = null): int
{
    logmsg('[RUN] '.$command);
    passthru($command, $rc);
    if ($rc !== 0 && $fatalCode !== null) {
        fatal("Command failed (rc={$rc}): {$command}", $fatalCode);
    }
    if ($rc !== 0) {
        logmsg("[WARN] Command failed (rc={$rc}): {$command}");
        logEvent('command_warn', ['command' => $command, 'rc' => $rc]);
    }

    return $rc;
}

/**
 * Check whether a candidate PHP binary is a usable CLI interpreter.
 */
function pmssPhpCliCandidateIsUsable(string $candidate): bool
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return false;
    }
    if (strpos($candidate, '/') !== false && (!is_file($candidate) || !is_executable($candidate))) {
        return false;
    }

    $command = escapeshellarg($candidate).' -r '.escapeshellarg('exit(PHP_SAPI === "cli" ? 0 : 1);');
    $output = [];
    $rc = 1;
    @exec($command, $output, $rc);
    return $rc === 0;
}

/**
 * Resolve the currently usable PHP CLI after a dist-upgrade may replace it.
 */
function pmssResolvePhpCliBinary(): string
{
    $pathResolved = trim((string) @shell_exec('command -v php 2>/dev/null'));
    $candidates = $pathResolved !== '' ? [$pathResolved] : [];
    foreach ([
        '/usr/bin/php',
        '/usr/local/bin/php',
        '/usr/bin/php8.4',
        '/usr/bin/php8.3',
        '/usr/bin/php8.2',
        '/usr/bin/php8.1',
        '/usr/bin/php7.4',
        '/usr/bin/php7.3',
    ] as $candidate) {
        $candidates[] = $candidate;
    }

    foreach (array_unique($candidates) as $candidate) {
        if (pmssPhpCliCandidateIsUsable($candidate)) {
            return $candidate;
        }
    }

    return 'php';
}

/**
 * Build a shell-safe PHP CLI invocation for refreshed bootstrap children.
 *
 * @param array<int, string> $args
 */
function pmssBootstrapPhpCommand(string $script, array $args = []): string
{
    $command = escapeshellarg(pmssResolvePhpCliBinary()).' '.escapeshellarg($script);
    foreach ($args as $arg) {
        $command .= ' '.escapeshellarg($arg);
    }

    return $command;
}

function pmssLastFilesystemError(): string
{
    $error = error_get_last();
    if (!is_array($error) || !isset($error['message'])) {
        return '';
    }

    $message = trim((string) $error['message']);
    return $message !== '' ? ': '.$message : '';
}

/**
 * Write bootstrap-managed files with visible failure reporting.
 */
function pmssWriteBootstrapFile(string $path, string $content, string $label, ?int $mode = null, int $flags = 0): bool
{
    if (@file_put_contents($path, $content, $flags) === false) {
        logmsg('[WARN] Failed to write '.$label.': '.$path.pmssLastFilesystemError());
        logEvent('bootstrap_file_write_failed', ['label' => $label, 'path' => $path]);
        return false;
    }

    if ($mode !== null && !@chmod($path, $mode)) {
        logmsg('[WARN] Failed to chmod '.$label.' to '.sprintf('%04o', $mode).': '.$path.pmssLastFilesystemError());
        logEvent('bootstrap_file_chmod_failed', ['label' => $label, 'path' => $path, 'mode' => sprintf('%04o', $mode)]);
        return false;
    }

    return true;
}

function pmssBootstrapPathLooksUnsafe(string $path): bool
{
    return $path === '' || $path === '/' || strpos($path, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $path) === 1;
}

function pmssBootstrapPathHasGeneratedPrefix(string $path, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if (strpos($path, $prefix) === 0 && strlen($path) > strlen($prefix)) {
            return true;
        }
    }

    return false;
}

function pmssIsSafeUpdateRemovePath(string $path): bool
{
    $path = rtrim($path, '/');
    return !pmssBootstrapPathLooksUnsafe($path)
        && pmssBootstrapPathHasGeneratedPrefix($path, [
            rtrim(sys_get_temp_dir(), '/').'/pmss-update-',
            '/scripts.pmss-staging-',
            '/scripts.pmss-backup-',
            '/etc/seedbox.pmss-staging-',
            '/etc/seedbox.pmss-backup-',
        ]);
}

function pmssBootstrapTempRoot(): string
{
    $testRoot = getenv('PMSS_TEST_TEMP_ROOT');
    if (is_string($testRoot) && $testRoot !== '') {
        return rtrim($testRoot, '/');
    }

    return rtrim(sys_get_temp_dir(), '/');
}

function pmssIsSafeAtomicSwapDirectoryPath(string $target, string $staging, string $backup): bool
{
    $target = rtrim($target, '/');
    $staging = rtrim($staging, '/');
    $backup = rtrim($backup, '/');

    if (is_link($target)) {
        return false;
    }

    foreach ([$target, $staging, $backup] as $path) {
        if (pmssBootstrapPathLooksUnsafe($path)) {
            return false;
        }
    }

    $allowedPairs = [
        '/scripts' => ['/scripts.pmss-staging-', '/scripts.pmss-backup-'],
        '/etc/seedbox' => ['/etc/seedbox.pmss-staging-', '/etc/seedbox.pmss-backup-'],
    ];
    if (isset($allowedPairs[$target])) {
        [$stagingPrefix, $backupPrefix] = $allowedPairs[$target];
        return pmssBootstrapPathHasGeneratedPrefix($staging, [$stagingPrefix])
            && pmssBootstrapPathHasGeneratedPrefix($backup, [$backupPrefix]);
    }

    // Hermetic tests may exercise the swap helper under generated temp roots.
    $tempPrefix = pmssBootstrapTempRoot().'/pmss-update-swap-';
    $fixtureRoot = dirname($target);
    return strpos($target, $tempPrefix) === 0
        && strlen($fixtureRoot) > strlen($tempPrefix)
        && dirname($staging) === $fixtureRoot
        && dirname($backup) === $fixtureRoot
        && basename($target) === 'target'
        && basename($staging) === 'staging'
        && basename($backup) === 'backup';
}

function pmssRemoveTreeBestEffort(string $path, string $label): void
{
    if (!pmssIsSafeUpdateRemovePath($path)) {
        logmsg('[WARN] Refusing unsafe '.$label.' removal path: '.$path);
        logEvent('unsafe_remove_refused', ['label' => $label, 'path' => $path]);
        return;
    }

    pmssRunBootstrapCommand('rm -rf '.escapeshellarg($path));
}

/**
 * Remove one file-like path and refuse directories so cron disables stay bounded.
 */
function pmssRemoveFile(string $path, string $label, ?string &$error = null): bool
{
    $error = null;
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    if (is_dir($path) && !is_link($path)) {
        $error = "Refusing to unlink directory for {$label}: {$path}";
        return false;
    }
    if (!@unlink($path)) {
        $error = "Failed to remove {$label}: {$path}".pmssLastFilesystemError();
        return false;
    }

    return true;
}

function pmssRemoveFileFatal(string $path, string $label): void
{
    $error = null;
    if (!pmssRemoveFile($path, $label, $error)) {
        fatal($error ?? "Failed to remove {$label}: {$path}", EXIT_COPY);
    }
}

/**
 * Allow content-clears only for the live skeleton tree or hermetic test fixtures.
 */
function pmssIsSafeDirectoryContentsClearPath(string $path): bool
{
    $path = rtrim($path, '/');
    if (pmssBootstrapPathLooksUnsafe($path)) {
        return false;
    }
    if ($path === '/etc/skel') {
        return true;
    }

    $tempRoot = rtrim(sys_get_temp_dir(), '/').'/';
    return strpos($path, $tempRoot) === 0
        && pmssBootstrapPathHasGeneratedPrefix(basename($path), ['pmss-update-clear-']);
}

/**
 * Remove one filesystem entry without following symlinks outside the cleared tree.
 */
function pmssRemoveFilesystemEntry(string $path, ?string &$error): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    if (is_link($path) || !is_dir($path)) {
        if (@unlink($path)) {
            return true;
        }
        $error = "Failed to remove filesystem entry {$path}".pmssLastFilesystemError();
        return false;
    }

    $entries = @scandir($path);
    if (!is_array($entries)) {
        $error = "Unable to read directory while removing {$path}".pmssLastFilesystemError();
        return false;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!pmssRemoveFilesystemEntry($path.'/'.$entry, $error)) {
            return false;
        }
    }
    if (!@rmdir($path)) {
        $error = "Failed to remove directory {$path}".pmssLastFilesystemError();
        return false;
    }

    return true;
}

function pmssIsSafeNestedScriptsLayoutRemovePath(string $path): bool
{
    $path = rtrim($path, '/');
    if (pmssBootstrapPathLooksUnsafe($path)) {
        return false;
    }
    if ($path === '/scripts/scripts') {
        return true;
    }

    // Test fixtures must keep the same /scripts/scripts shape under temp roots.
    $tempPrefix = pmssBootstrapTempRoot().'/pmss-update-nested-scripts-';
    return preg_match('#^'.preg_quote($tempPrefix, '#').'[^/]+/scripts/scripts$#', $path) === 1;
}

function pmssRemoveNestedScriptsLayout(string $path): bool
{
    if (!pmssIsSafeNestedScriptsLayoutRemovePath($path)) {
        logmsg('[WARN] Refusing unsafe nested scripts removal path: '.$path);
        logEvent('unsafe_remove_refused', ['label' => 'nested scripts layout', 'path' => $path]);
        return false;
    }

    $error = null;
    if (pmssRemoveFilesystemEntry($path, $error)) {
        return true;
    }

    logmsg('[WARN] Failed to remove nested scripts layout: '.($error ?? $path));
    logEvent('nested_scripts_remove_failed', ['path' => $path, 'error' => $error ?? 'unknown']);
    return false;
}

/**
 * Clear child entries while leaving the guarded root directory itself in place.
 */
function pmssClearDirectoryContents(string $path, string $label, ?string &$error = null): bool
{
    $error = null;
    $path = rtrim($path, '/');
    if (!pmssIsSafeDirectoryContentsClearPath($path)) {
        $error = "Refusing unsafe {$label} clear path: {$path}";
        return false;
    }
    if (is_link($path) || !is_dir($path)) {
        $error = "Refusing to clear missing or non-directory {$label}: {$path}";
        return false;
    }

    $entries = @scandir($path);
    if (!is_array($entries)) {
        $error = "Unable to read {$label} before clearing: {$path}".pmssLastFilesystemError();
        return false;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!pmssRemoveFilesystemEntry($path.'/'.$entry, $error)) {
            return false;
        }
    }

    return true;
}

/**
 * Swap a staged directory into place and restore the old tree if the final rename fails.
 */
function pmssAtomicSwapDirectory(string $target, string $staging, string $backup, string $label): void
{
    if (!pmssIsSafeAtomicSwapDirectoryPath($target, $staging, $backup)) {
        fatal("Refusing unsafe atomic swap paths for {$label}", EXIT_COPY);
    }
    if (!is_dir($staging)) {
        fatal("Staging directory missing for {$label}: {$staging}", EXIT_COPY);
    }
    if ((file_exists($target) || is_link($target)) && !is_dir($target)) {
        fatal("{$target} exists but is not a directory", EXIT_COPY);
    }

    $hadTarget = is_dir($target);
    if ($hadTarget && !@rename($target, $backup)) {
        fatal("Failed to rename {$target} to backup for atomic swap".pmssLastFilesystemError(), EXIT_COPY);
    }

    if (@rename($staging, $target)) {
        return;
    }

    $swapError = pmssLastFilesystemError();
    if ($hadTarget && is_dir($backup) && !file_exists($target) && @rename($backup, $target)) {
        logmsg("[WARN] Restored previous {$label} tree after failed atomic swap");
        logEvent('atomic_swap_rollback', ['label' => $label, 'target' => $target, 'backup' => $backup]);
        fatal("Failed to rename staged {$target} into place{$swapError}; restored previous tree", EXIT_COPY);
    }

    fatal("Failed to rename staged {$target} into place{$swapError}; rollback unavailable", EXIT_COPY);
}

/**
 * Best-effort restore of PMSS root cron after updates that temporarily disable it.
 *
 * Phase 1 keeps `/etc/cron.d/pmss` live until the immediate phase-2 handoff.
 * If phase 2 exits early or the bootstrap receives a termination signal after
 * that handoff, restore the cron template before update.php exits.
 */
function pmssRestoreRootCronBackup(string $context): bool
{
    $target = '/etc/cron.d/pmss';
    $backup = $GLOBALS['PMSS_ROOT_CRON_BACKUP_CONTENT'] ?? null;
    if (file_exists($target) || is_link($target)) {
        return true;
    }
    if (!is_string($backup) || $backup === '') {
        return false;
    }
    if (@file_put_contents($target, $backup, LOCK_EX) === false) {
        logmsg('[WARN] Failed to restore root cron backup after '.$context);
        return false;
    }

    @chmod($target, 0644);
    logmsg('[WARN] Restored root cron from phase-1 backup after '.$context);
    return true;
}

/**
 * Ensure cron itself is not left masked or stopped.
 */
function pmssEnsureCronServiceActiveBootstrap(string $context): void
{
    if (!is_dir('/run/systemd/system')) {
        logmsg('[SKIP] Ensuring cron service is active (systemd unavailable)');
        return;
    }
    $systemctl = trim((string) @shell_exec('command -v systemctl 2>/dev/null'));
    if ($systemctl === '') {
        logmsg('[SKIP] Ensuring cron service is active (systemctl missing)');
        return;
    }

    $state = trim((string) @shell_exec('systemctl is-enabled cron.service 2>/dev/null'));
    if ($state === 'masked') {
        logmsg('[WARN] cron.service is masked during '.$context.'; unmasking immediately');
        pmssRunBootstrapCommand('systemctl unmask cron.service || true');
    }

    pmssRunBootstrapCommand('systemctl enable --now cron.service || true');
}

function restoreRootCronBestEffort(string $context): bool
{
    $helper   = '/scripts/util/setupRootCron.php';
    $template = '/etc/seedbox/config/root.cron';
    $target   = '/etc/cron.d/pmss';

    if (!file_exists($helper)) {
        logmsg('[WARN] setupRootCron.php missing; cannot restore root cron after '.$context);
        pmssEnsureCronServiceActiveBootstrap($context.' root cron restore');
        return pmssRestoreRootCronBackup($context);
    }
    if (!file_exists($template)) {
        logmsg('[WARN] root.cron template missing; cannot restore root cron after '.$context);
        pmssEnsureCronServiceActiveBootstrap($context.' root cron restore');
        return pmssRestoreRootCronBackup($context);
    }
    // Always re-run setupRootCron.php — it deploys the template idempotently
    // (`install -m 0644 …`). The previous "skip if target exists" short-circuit
    // meant new cron entries added to etc/seedbox/config/root.cron were never
    // deployed to existing hosts via --scripts-only updates; the host kept the
    // entries present at the time of its last full update only.
    if (file_exists($target)) {
        logmsg('[INFO] Refreshing root cron from updated template after '.$context);
    } else {
        logmsg('[INFO] Restoring root cron after '.$context);
    }
    pmssRunBootstrapCommand(pmssBootstrapPhpCommand($helper));
    pmssEnsureCronServiceActiveBootstrap($context.' root cron restore');
    if (!file_exists($target) && !is_link($target)) {
        return pmssRestoreRootCronBackup($context);
    }

    return true;
}

function pmssRootCronShutdownRestore(string $context = 'shutdown'): void
{
    if (empty($GLOBALS['PMSS_ROOT_CRON_DISABLED_BY_UPDATE'])) {
        return;
    }

    restoreRootCronBestEffort($context);
    $GLOBALS['PMSS_ROOT_CRON_DISABLED_BY_UPDATE'] = false;
}

function pmssRootCronSignalHandler(int $signal): void
{
    logmsg('[WARN] update.php received signal '.$signal.'; restoring root cron before exit');
    pmssRootCronShutdownRestore('signal '.$signal);
    exit(128 + $signal);
}

function pmssRegisterRootCronRestoreGuard(): void
{
    if (!empty($GLOBALS['PMSS_ROOT_CRON_RESTORE_GUARD_REGISTERED'])) {
        return;
    }

    register_shutdown_function('pmssRootCronShutdownRestore', 'shutdown');
    if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        foreach (['SIGTERM', 'SIGINT', 'SIGHUP'] as $signalName) {
            if (defined($signalName)) {
                pcntl_signal(constant($signalName), 'pmssRootCronSignalHandler');
            }
        }
    }

    $GLOBALS['PMSS_ROOT_CRON_RESTORE_GUARD_REGISTERED'] = true;
}

function pmssDisableRootCronForUpdateStep2(): void
{
    pmssRegisterRootCronRestoreGuard();
    $target = '/etc/cron.d/pmss';
    $GLOBALS['PMSS_ROOT_CRON_DISABLED_BY_UPDATE'] = true;
    $GLOBALS['PMSS_ROOT_CRON_BACKUP_CONTENT'] = is_file($target) && !is_link($target)
        ? @file_get_contents($target)
        : null;

    if (file_exists($target) || is_link($target)) {
        pmssRemoveFileFatal($target, 'root cron file');
        logmsg('[INFO] Disabled /etc/cron.d/pmss for immediate update-step2 handoff; shutdown guard will restore it');
        return;
    }

    logmsg('[WARN] /etc/cron.d/pmss was already missing before update-step2 handoff; shutdown guard will restore from template');
}

/**
 * Best-effort refresh of skeleton/config permissions after snapshot staging.
 *
 * Phase 1 hardens `/etc/skel` and `/etc/seedbox`, so flows that do not finish
 * phase 2 must rerun the dedicated helper to restore expected traversal and
 * file visibility for tenant-facing services.
 */
function restorePermissionsBestEffort(string $context): void
{
    $helper = '/scripts/util/setupPermissions.php';

    if (!file_exists($helper)) {
        logmsg('[WARN] setupPermissions.php missing; cannot refresh system permissions after '.$context);
        return;
    }

    logmsg('[INFO] Refreshing skeleton/config permissions after '.$context);
    pmssRunBootstrapCommand(pmssBootstrapPhpCommand($helper));
}

function ensureSnapshot(string $tmp): void
{
    $required = [
        [$tmp.'/scripts', 'scripts tree', 'directory'],
        [$tmp.'/scripts/update.php', 'update bootstrap', 'file'],
        [$tmp.'/scripts/util/update-step2.php', 'phase 2 bootstrap', 'file'],
    ];
    foreach ($required as $requirement) {
        [$path, $label, $type] = $requirement;
        if (!file_exists($path) && !is_link($path)) {
            fatal("Snapshot missing required file: {$path}", EXIT_COPY);
        }
        $error = null;
        if (!pmssIsSafeSnapshotPath($tmp, $path, $type, $error)) {
            fatal("Snapshot {$label} failed safety checks: ".($error ?? $path), EXIT_COPY);
        }
    }
}

/** Return true when a resolved path is equal to or beneath a directory. */
function pmssPathIsWithinDirectory(string $path, string $directory): bool
{
    $directory = rtrim($directory, '/');
    return $path === $directory || strpos($path, $directory.'/') === 0;
}

/** Detect symlink components between a guarded directory and target path. */
function pmssPathHasSymlinkSegment(string $directory, string $path): bool
{
    $directory = rtrim($directory, '/');
    $path = rtrim($path, '/');
    if (!pmssPathIsWithinDirectory($path, $directory)) {
        return true;
    }

    $relative = ltrim(substr($path, strlen($directory)), '/');
    if ($relative === '') {
        return is_link($directory);
    }

    $current = $directory;
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '') {
            continue;
        }
        $current .= '/'.$segment;
        if (is_link($current)) {
            return true;
        }
    }

    return false;
}

/**
 * Validate fetched snapshot paths before copying them into live system trees.
 */
function pmssIsSafeSnapshotPath(string $snapshotRoot, string $path, string $expectedType, ?string &$error = null): bool
{
    $error = null;
    $snapshotRoot = rtrim($snapshotRoot, '/');
    $path = rtrim($path, '/');
    if ($snapshotRoot === '' || $path === '' || strpos($snapshotRoot, "\0") !== false || strpos($path, "\0") !== false) {
        $error = 'empty or invalid snapshot path';
        return false;
    }
    if (preg_match('#(^|/)\.\.(/|$)#', $snapshotRoot) === 1 || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
        $error = "snapshot path contains parent traversal: {$path}";
        return false;
    }

    $rootReal = realpath($snapshotRoot);
    if ($rootReal === false || !is_dir($rootReal) || is_link($snapshotRoot)) {
        $error = "snapshot root is not a real directory: {$snapshotRoot}";
        return false;
    }
    if (!file_exists($path) && !is_link($path)) {
        $error = "snapshot path is missing: {$path}";
        return false;
    }
    if (is_link($path)) {
        $error = "snapshot path must not be a symlink: {$path}";
        return false;
    }
    if (pmssPathHasSymlinkSegment($snapshotRoot, $path)) {
        $error = "snapshot path contains a symlink segment: {$path}";
        return false;
    }

    $pathReal = realpath($path);
    if ($pathReal === false || !pmssPathIsWithinDirectory($pathReal, $rootReal)) {
        $error = "snapshot path escapes root: {$path}";
        return false;
    }

    if ($expectedType === 'directory') {
        $matchesType = is_dir($path);
    } elseif ($expectedType === 'file') {
        $matchesType = is_file($path);
    } elseif ($expectedType === 'entry') {
        $matchesType = is_dir($path) || is_file($path);
    } else {
        $error = "unknown snapshot path type: {$expectedType}";
        return false;
    }

    if (!$matchesType) {
        $error = "snapshot path is not a {$expectedType}: {$path}";
        return false;
    }

    return true;
}

/**
 * Validate symlinks inside a snapshot tree before cp preserves them live.
 */
function pmssValidateSnapshotTreeLinks(string $snapshotRoot, string $treePath, ?string &$error = null): bool
{
    $error = null;
    $treePath = rtrim($treePath, '/');
    if (!pmssIsSafeSnapshotPath($snapshotRoot, $treePath, 'directory', $error)) {
        return false;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($treePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entryPath => $entry) {
            $path = is_string($entryPath) ? $entryPath : $entry->getPathname();
            if (!$entry->isLink()) {
                continue;
            }

            $target = @readlink($path);
            if (!is_string($target) || $target === '') {
                $error = "snapshot symlink target is unreadable: {$path}";
                return false;
            }
            if ($target[0] === '/' || strpos($target, "\0") !== false) {
                $error = "snapshot symlink has unsafe target {$target}: {$path}";
                return false;
            }
        }
    } catch (UnexpectedValueException $exception) {
        $error = "unable to scan snapshot tree {$treePath}: ".$exception->getMessage();
        return false;
    }

    return true;
}

function directoryHasContent(string $path): bool
{
    if (is_link($path) || !is_dir($path)) {
        return false;
    }
    $handle = @opendir($path);
    if ($handle === false) {
        return false;
    }
    while (($entry = readdir($handle)) !== false) {
        if ($entry !== '.' && $entry !== '..') {
            closedir($handle);
            return true;
        }
    }
    closedir($handle);
    return false;
}

// Gate missing/dry-run staging before tree-specific work.
function pmssStageSnapshotTreeIfPresent(string $snapshotRoot, string $source, string $tree, bool $dryRun, string $dryRunMessage, callable $stageTree): void
{
    if (!file_exists($source) && !is_link($source)) {
        logmsg("[WARN] Snapshot {$tree} tree missing or empty, skipping copy");
        logEvent('tree_skipped', ['tree' => $tree]);
        return;
    }
    $error = null;
    if (!pmssIsSafeSnapshotPath($snapshotRoot, $source, 'directory', $error)) {
        fatal("Unsafe snapshot {$tree} tree: ".($error ?? $source), EXIT_COPY);
    }
    if (!pmssValidateSnapshotTreeLinks($snapshotRoot, $source, $error)) {
        fatal("Unsafe snapshot {$tree} tree links: ".($error ?? $source), EXIT_COPY);
    }
    if (!directoryHasContent($source)) {
        logmsg("[WARN] Snapshot {$tree} tree missing or empty, skipping copy");
        logEvent('tree_skipped', ['tree' => $tree]);
        return;
    }
    if ($dryRun) {
        logmsg($dryRunMessage);
        return;
    }

    $stageTree($source);
}

/**
 * Stage snapshot trees into place.
 *
 * Copies `scripts/`, `etc/`, and `var/` from `$tmp` into the live filesystem.
 * Wipes previous `/scripts/*`, clears `/etc/skel/*` when snapshot contains a
 * skel tree, and hardens permissions. When `$dryRun` is true, only logs planned
 * actions. Fatal on copy/permission failures.
 */
function stageSnapshot(string $tmp, bool $dryRun): void
{
    ensureSnapshot($tmp);

    $runId = bin2hex(random_bytes(4));

    // Stage /scripts with an atomic rename swap.
    $scriptsSource = $tmp.'/scripts';
    pmssStageSnapshotTreeIfPresent($tmp, $scriptsSource, 'scripts', $dryRun, "[DRY RUN] Would atomically swap /scripts from {$scriptsSource}", function (string $scriptsSource) use ($runId): void {
        $scriptsStaging = '/scripts.pmss-staging-'.$runId;
        $scriptsBackup  = '/scripts.pmss-backup-'.$runId;
        pmssRemoveTreeBestEffort($scriptsStaging, 'scripts staging');
        pmssRunBootstrapCommand(sprintf('cp -a %s/. %s', escapeshellarg($scriptsSource), escapeshellarg($scriptsStaging)), EXIT_COPY);

        pmssAtomicSwapDirectory('/scripts', $scriptsStaging, $scriptsBackup, 'scripts');
        pmssRemoveTreeBestEffort($scriptsBackup, 'scripts backup');
    });

    // Stage /etc tree with atomic swap for /etc/seedbox and overlay for the rest.
    $etcSource = $tmp.'/etc';
    pmssStageSnapshotTreeIfPresent($tmp, $etcSource, 'etc', $dryRun, "[DRY RUN] Would atomically swap /etc/seedbox and overlay other /etc files from {$etcSource}", function (string $etcSource) use ($runId, $tmp): void {
        $skelSource = $etcSource.'/skel';
        if (file_exists($skelSource) || is_link($skelSource)) {
            $error = null;
            if (!pmssIsSafeSnapshotPath($tmp, $skelSource, 'directory', $error)) {
                fatal("Unsafe snapshot skel tree: ".($error ?? $skelSource), EXIT_COPY);
            }
        }
        if (is_dir($skelSource) && is_dir('/etc/skel')) {
            $error = null;
            if (!pmssClearDirectoryContents('/etc/skel', 'skeleton tree', $error)) {
                fatal($error ?? 'Unable to clear skeleton tree: /etc/skel', EXIT_COPY);
            }
        }

        // Atomic swap for /etc/seedbox
        $seedboxSource  = $etcSource.'/seedbox';
        $seedboxStaging = '/etc/seedbox.pmss-staging-'.$runId;
        $seedboxBackup  = '/etc/seedbox.pmss-backup-'.$runId;
        $haveSeedboxSnapshot = false;
        if (file_exists($seedboxSource) || is_link($seedboxSource)) {
            $error = null;
            if (!pmssIsSafeSnapshotPath($tmp, $seedboxSource, 'directory', $error)) {
                fatal("Unsafe snapshot seedbox tree: ".($error ?? $seedboxSource), EXIT_COPY);
            }
            $haveSeedboxSnapshot = directoryHasContent($seedboxSource);
        }
        if ($haveSeedboxSnapshot || is_dir('/etc/seedbox')) {
            pmssRemoveTreeBestEffort($seedboxStaging, 'seedbox staging');
            if (is_dir('/etc/seedbox')) {
                pmssRunBootstrapCommand(sprintf('cp -a %s %s', escapeshellarg('/etc/seedbox'), escapeshellarg($seedboxStaging)), EXIT_COPY);
            } else {
                pmssEnsureDirectory($seedboxStaging);
            }
            if ($haveSeedboxSnapshot) {
                pmssRunBootstrapCommand(sprintf('cp -a %s/. %s', escapeshellarg($seedboxSource), escapeshellarg($seedboxStaging)), EXIT_COPY);
            }
            pmssAtomicSwapDirectory('/etc/seedbox', $seedboxStaging, $seedboxBackup, 'seedbox config');
            pmssRemoveTreeBestEffort($seedboxBackup, 'seedbox backup');
        }

        // Overlay remaining /etc content (excluding seedbox) to preserve local edits.
        $entries = @scandir($etcSource);
        if (!is_array($entries)) {
            fatal("Unable to read snapshot etc tree: {$etcSource}".pmssLastFilesystemError(), EXIT_COPY);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'seedbox') {
                continue;
            }
            $path = $etcSource.'/'.$entry;
            $error = null;
            if (!pmssIsSafeSnapshotPath($tmp, $path, 'entry', $error)) {
                fatal("Unsafe snapshot etc entry: ".($error ?? $path), EXIT_COPY);
            }
            pmssRunBootstrapCommand('cp -rpu '.escapeshellarg($path).' /etc', EXIT_COPY);
        }
    });

    // Stage /var as before.
    $varSource = $tmp.'/var';
    pmssStageSnapshotTreeIfPresent($tmp, $varSource, 'var', $dryRun, "[DRY RUN] Would copy var from {$varSource}", function (string $varSource): void {
        pmssRunBootstrapCommand('cp -a '.escapeshellarg($varSource).' /', EXIT_COPY);
    });

    if ($dryRun) {
        return;
    }

    pmssRunBootstrapCommand('chmod -R o-rwx /scripts /root /etc/skel /etc/seedbox', EXIT_COPY);
    pmssRunBootstrapCommand('find /scripts -type f -name "*.php" -exec chmod 0750 {} +', EXIT_COPY);
    pmssRunBootstrapCommand('chmod 0750 /scripts/update.php', EXIT_COPY);
    flattenScriptsLayout();
}

function flattenScriptsLayout(): void
{
    $nested = '/scripts/scripts';
    if (!is_dir($nested)) {
        return;
    }
    logmsg('Detected nested /scripts/scripts layout, flattening');
    logEvent('scripts_flatten', ['status' => 'start']);
    pmssRunBootstrapCommand(sprintf('cp -a %s/. %s', escapeshellarg($nested), escapeshellarg('/scripts')));
    pmssRemoveNestedScriptsLayout($nested);
    if (!file_exists('/scripts/util/update-step2.php')) {
        logmsg('[WARN] update-step2.php missing after flattening');
        logEvent('scripts_flatten', ['status' => 'update_step2_missing']);
    } else {
        logEvent('scripts_flatten', ['status' => 'ok']);
    }
}

function collectCommitHash(string $tmp): string
{
    $rev = @shell_exec('cd '.escapeshellarg($tmp).' && git rev-parse HEAD');
    return trim((string)$rev);
}

/**
 * Record the applied version for auditability.
 *
 * Writes a one-line spec with timestamp to VERSION_FILE and a JSON metadata
 * object to VERSION_META. Skips writes when dry-run is enabled.
 */
function recordVersion(string $spec, array $details, bool $dryRun): void
{
    if ($dryRun) {
        logmsg('[DRY RUN] Not writing version metadata');
        return;
    }

    pmssEnsureDirectory(VERSION_DIR);

    $timestamp = time();
    $line      = $spec.'@'.date('Y-m-d H:i', $timestamp);
    $details['recorded_spec'] = $spec;
    $details['timestamp']     = date('c', $timestamp);

    $encodedMeta = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encodedMeta === false) {
        $jsonError = function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown JSON error';
        logmsg('[WARN] Failed to encode version metadata: '.$jsonError);
        logEvent('version_metadata_encode_failed', ['error' => $jsonError]);
        $encodedMeta = '{}';
    }

    pmssWriteBootstrapFile(VERSION_FILE, $line.PHP_EOL, 'version marker', null, LOCK_EX);
    pmssWriteBootstrapFile(VERSION_META, $encodedMeta.PHP_EOL, 'version metadata', null, LOCK_EX);

    // Backwards-compatibility: some tooling expects /etc/seedbox/runtime/version.
    // #TODO(Q4/2027): remove this compatibility write once all consumers use VERSION_FILE.
    pmssEnsureDirectory(dirname(VERSION_RUNTIME_FILE));
    pmssWriteBootstrapFile(VERSION_RUNTIME_FILE, $line.PHP_EOL, 'runtime version marker', null, LOCK_EX);

    // #TODO Ensure consistent permissions (0640) on version metadata and any
    //       future config artifacts written here.
}

function cleanup(string $path): void
{
    if ($path !== '' && is_dir($path)) {
        pmssRemoveTreeBestEffort($path, 'temporary workspace');
    }
}

function maybeSelfUpdate(array $argv, bool $dryRun, bool $skipSelfUpdate, string $originalHash): bool
{
    if ($dryRun || $skipSelfUpdate) {
        return false;
    }
    $updatedHash = currentUpdaterHash();
    if ($originalHash === '' || $updatedHash === '' || $originalHash === $updatedHash) {
        return false;
    }

    logmsg('update.php changed during snapshot; re-running refreshed bootstrap');
    logEvent('self_update_restart', ['previous_hash' => $originalHash, 'new_hash' => $updatedHash]);

    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === SELF_UPDATE_SKIP_FLAG) {
            continue;
        }
        $args[] = $arg;
    }
    $args[] = SELF_UPDATE_SKIP_FLAG;

    $command = pmssBootstrapPhpCommand(__FILE__, $args);

    passthru($command, $rc);
    if ($rc !== 0) {
        fatal('Self-refresh of update.php failed with status '.$rc, $rc);
    }
    return true;
}

function currentUpdaterHash(): string
{
    $hash = @hash_file('sha256', __FILE__);
    return $hash === false ? '' : $hash;
}

/**
 * Hand off to phase 2 orchestrator when appropriate.
 *
 * Exports the JSON log path, handles dry-run and missing file cases gracefully,
 * logs duration and exit status as JSON events, and fatals on non-zero status.
 */
function runUpdateStep2(bool $dryRun): void
{
    putenv('PMSS_JSON_LOG='.JSON_LOG);

    if ($dryRun) {
        logmsg('Skipping update-step2.php (dry run)');
        logEvent('update_step2_skipped', ['reason' => 'dry_run']);
        return;
    }

    if (!file_exists('/scripts/util/update-step2.php')) {
        logmsg('Skipping update-step2.php (file missing after copy)');
        logEvent('update_step2_skipped', ['reason' => 'missing']);
        return;
    }

    // Preflight expectations before handing off to phase 2:
    //   * Ensure at least ~3 GB free on the root filesystem and /home.
    //   * Verify network reachability (implicit if we got this far).
    checkDiskSpace();

    $correlationId = pmssCorrelationId(false);
    $handoffLog = 'Handing off to update-step2.php';
    if ($correlationId !== '') {
        $handoffLog .= ' (pmss_correlation_id='.$correlationId.')';
    }
    logmsg($handoffLog);
    logEvent('update_step2_start');
    pmssDisableRootCronForUpdateStep2();
    $start = microtime(true);
    passthru(pmssBootstrapPhpCommand('/scripts/util/update-step2.php'), $rc);
    $duration = round(microtime(true) - $start, 3);
    restoreRootCronBestEffort('update-step2 handoff');
    $GLOBALS['PMSS_ROOT_CRON_DISABLED_BY_UPDATE'] = false;

    // Interpret only valid 128+signal exit codes (e.g. SIGKILL -> 137).
    $status  = $rc === 0 ? 'ok' : 'error';
    $details = ['status' => $status, 'rc' => $rc, 'duration' => $duration];
    if ($rc !== 0 && $rc >= 129 && $rc <= 192) {
        $signal = $rc - 128;
        $name   = [9 => 'SIGKILL', 15 => 'SIGTERM'][$signal] ?? '';
        $details['signal']      = $signal;
        $details['signal_name'] = $name;

        $human = 'terminated by signal '.$signal.($name !== '' ? ' ('.$name.')' : '');
        logmsg(sprintf('[ERROR] update-step2.php was %s; check kernel logs for OOM/kill entries', $human));
        logEvent('update_step2_signal', [
            'rc'          => $rc,
            'signal'      => $signal,
            'signal_name' => $name,
            'duration'    => $duration,
        ]);
        logmsg('Partial step/profile data (if any) are under:');
        logmsg('  - JSON:   '.JSON_LOG);
        logmsg('  - Profile: '.JSON_LOG.'.profile.json');
    } elseif ($rc !== 0) {
        $details['exit_class'] = $rc === 255 ? 'general_error' : 'exit_code';
        if ($rc === 255) {
            logmsg('[ERROR] update-step2.php exited with status 255 (general error / unhandled exception)');
        }
        logEvent('update_step2_exit_code', [
            'rc'         => $rc,
            'exit_class' => $details['exit_class'],
            'duration'   => $duration,
        ]);
    }
    logEvent('update_step2_end', $details);
    if ($rc !== 0) {
        restorePermissionsBestEffort('update-step2 failure');
        fatal('update-step2.php exited with status '.$rc, $rc);
    }
}

function checkDiskSpace(): void
{
    // 3 GiB in bytes
    $required = 3.0 * 1024 * 1024 * 1024;
    $paths = ['/', '/home'];

    foreach ($paths as $path) {
        if (!is_dir($path)) {
            continue;
        }
        $free = @disk_free_space($path);
        if ($free === false) {
            logmsg("[WARN] Unable to determine free space for {$path}");
            continue;
        }
        if ($free < $required) {
            $availableGb = round($free / 1073741824, 2);
            $requiredGb  = round($required / 1073741824, 2);
            $msg = "Insufficient free space on {$path}: {$availableGb} GiB available, {$requiredGb} GiB required";
            logEvent('preflight_error', ['check' => 'disk_space', 'path' => $path, 'available_bytes' => $free, 'required_bytes' => $required]);
            fatal($msg, EXIT_COPY);
        }
    }
    logmsg('[INFO] Preflight disk space check passed');
}

/**
 * @param bool|string $distUpgrade
 * @param bool $deferRootCronRestore
 */
function maybeRunDistUpgrade($distUpgrade, bool $deferRootCronRestore = false): void
{
    if ($distUpgrade === false) {
        return;
    }
    if ($distUpgrade === true) {
        fatal("You must specify a maximum version for dist-upgrade (e.g. --dist-upgrade=11 or --dist-upgrade=bullseye).", EXIT_PARSE);
    }
    logEvent('dist_upgrade_start', ['target' => $distUpgrade]);
    $helper = '/scripts/lib/update/distUpgrade.php';
    if (!file_exists($helper)) {
        fatal('Dist-upgrade helper missing after snapshot staging.', EXIT_DIST);
    }
    require_once $helper;
    if (!function_exists('pmssRunDistUpgrade')) {
        fatal('Dist-upgrade helper missing entrypoint after snapshot staging.', EXIT_DIST);
    }
    logmsg('[RUN] dist-upgrade helper');
    $rc = pmssRunDistUpgrade((string) $distUpgrade);
    logEvent('dist_upgrade_end', ['rc' => $rc]);

    if ($rc !== 0) {
        restoreRootCronBestEffort('dist-upgrade');
        fatal('dist-upgrade helper exited with status '.$rc, EXIT_DIST);
    }

    // When phase 2 will run, keep the root cron template refresh for the
    // handoff/end guard. Otherwise restore immediately.
    if ($deferRootCronRestore) {
        logmsg('[INFO] Deferring root cron template refresh until update-step2');
    } else {
        restoreRootCronBestEffort('dist-upgrade');
    }

    // Best-effort refresh of MOTD after a successful dist-upgrade run. This
    // gives operators immediate visibility without requiring a reboot.
    $motd = '/scripts/util/motdGenerate.php';
    if (file_exists($motd)) {
        pmssRunBootstrapCommand(pmssBootstrapPhpCommand($motd));
    }
}

function bootstrapMain(array $argv): void
{
    ensureRoot();
    pmssAcquireUpdateLock();

    $startTime    = microtime(true);
    $originalHash = currentUpdaterHash();

    logmsg('Update log: /var/log/pmss/update.log (fallback /tmp/update.log)');
    logmsg('JSON events: '.JSON_LOG);
    $correlationId = pmssCorrelationId();
    logmsg('PMSS correlation ID: '.$correlationId);

    $options = parseArguments($argv);

    // Invariant: scripts-only mode is for emergency script refreshes and must
    // never modify package state. Refuse conflicting flag combinations early.
    if ($options['scripts_only'] && $options['dist_upgrade']) {
        fatal('Cannot combine --scripts-only with --dist-upgrade; scripts-only must never modify packages.', EXIT_PARSE);
    }
    if (!$options['dry_run']) {
        pmssEnsureCronServiceActiveBootstrap('update.php start');
    }

    // Only normalize apt sources for full update or dist-upgrade flows. Scripts
    // refreshes intentionally leave apt configuration untouched.
    if (!$options['scripts_only']) {
        normalizeAptSources();
    } else {
        logmsg('[INFO] Skipping apt sources normalization for --scripts-only run');
    }

    $specRaw = normaliseSpec($options['spec']);
    if ($specRaw === '') {
        fatal("Invalid source spec '{$options['spec']}'", EXIT_PARSE);
    }
    $spec = parseSpec($specRaw);

    logmsg('Source spec → '.json_encode($spec));
    logEvent('update_start', [
        'spec'         => $specRaw,
        'dry_run'      => $options['dry_run'],
        'scripts_only' => $options['scripts_only'],
        'repo'         => $spec['repo'],
        'branch'       => $spec['branch'],
        'pin'          => $spec['pin'],
    ]);

    // Remove /etc/cron.d/updateQuotas if present: PMSS cron entries must be in /etc/cron.d/pmss
    // (deployed from /etc/seedbox/config/root.cron). This is a safety guard to prevent any
    // recurrence of the 2025-12-08 /home wipe class when cron runs during partial-update windows.
    // See incident report: docs/incidents/2025-12-08-home-wipe-updateQuotas-listUsers.md
    $obsoleteQuotaCron = '/etc/cron.d/updateQuotas';
    if (file_exists($obsoleteQuotaCron) || is_link($obsoleteQuotaCron)) {
        pmssRemoveFileFatal($obsoleteQuotaCron, 'legacy quota cron file');
        logmsg('[INFO] Removed legacy updateQuotas cron entry to prevent quota-refresh regression');
    }

    $distUpgradeHelper = '/scripts/lib/update/distUpgrade.php';
    if ($options['dist_upgrade'] && !file_exists($distUpgradeHelper)) {
        logmsg('[INFO] Dist-upgrade helper missing; fetching snapshot to provision it...');
    }

    // If we just restarted from a self-update, the new code is already staged.
    // Skip re-fetching to avoid confusing logs and double work.
    if (!$options['skip_self_update']) {
        $workdir = createWorkdir();

        try {
            fetchSnapshot($spec, $workdir);
            $spec['commit'] = $spec['type'] === 'git' ? collectCommitHash($workdir) : '';
            stageSnapshot($workdir, $options['dry_run']);

            $versionSpec = $spec['type'] === 'release'
                ? 'release'.($spec['pin'] !== '' ? ':'.$spec['pin'] : '')
                : (($spec['repo'] === DEFAULT_REPO ? 'git/'.$spec['branch'] : 'git/'.$spec['repo'].':'.$spec['branch'])
                    .($spec['pin'] !== '' ? ':'.$spec['pin'] : ''));

            recordVersion($versionSpec, [
                'spec_input'      => $options['spec'],
                'spec_normalized' => $specRaw,
                'type'            => $spec['type'],
                'repo'            => $spec['repo'],
                'branch'          => $spec['branch'],
                'pin'             => $spec['pin'],
                'commit'          => $spec['commit'] ?? '',
            ], $options['dry_run']);

            logEvent('snapshot_applied', [
                'version_spec' => $versionSpec,
                'commit'       => $spec['commit'] ?? '',
                'dry_run'      => $options['dry_run'],
            ]);
        } finally {
            cleanup($workdir);
        }

        if (maybeSelfUpdate($argv, $options['dry_run'], $options['skip_self_update'], $originalHash)) {
            return;
        }
    }

    if ($options['dist_upgrade']) {
        maybeRunDistUpgrade($options['dist_upgrade'], true);
        logmsg('[INFO] Dist-upgrade complete; continuing with update-step2');
    }

    if ($options['scripts_only']) {
        // Scripts-only runs refresh /scripts and /etc trees but intentionally
        // skip the full phase 2 orchestrator. We still need to converge
        // skeleton and configuration permissions so services like rTorrent
        // continue to see readable config under /etc/seedbox after the
        // initial hardening in stageSnapshot().
        if (!$options['dry_run']) {
            restorePermissionsBestEffort('--scripts-only run');
            if (file_exists('/scripts/util/ftpConfig.php')) {
                logmsg('[INFO] Refreshing FTP configuration for --scripts-only run');
                pmssRunBootstrapCommand(pmssBootstrapPhpCommand('/scripts/util/ftpConfig.php'));
            }
            restoreRootCronBestEffort('scripts-only');
        }
        logmsg('Skipping update-step2.php (--scripts-only)');
        logEvent('update_step2_skipped', ['reason' => 'scripts_only']);
    } else {
        runUpdateStep2($options['dry_run']);
    }

    $duration = round(microtime(true) - $startTime, 3);
    $prefix   = $options['dry_run'] ? '[DRY RUN] ' : '';
    logmsg($prefix.'Update completed in '.$duration.'s');
    logEvent('update_complete', [
        'status'       => 'ok',
        'dry_run'      => $options['dry_run'],
        'scripts_only' => $options['scripts_only'],
        'duration'     => $duration,
    ]);
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    bootstrapMain($argv);
}
