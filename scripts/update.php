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
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
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
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
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
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
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
 * Detect Debian codename and major version from the local system.
 */
function detectDistroCodename(): array
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
            $parts = explode('.', $version);
            $major = $parts[0];
            if ($major === '10') $codename = 'buster';
            elseif ($major === '11') $codename = 'bullseye';
            elseif ($major === '12') $codename = 'bookworm';
            elseif ($major === '13') $codename = 'trixie';
        }
    }

    return [$codename, $major];
}

/**
 * Make sure /etc/apt/sources.list matches the detected suite before any apt command runs.
 */
function normalizeAptSources(): void
{
    [$codename, $major] = detectDistroCodename();
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

    if (@file_put_contents($sourcesPath, $content) !== false) {
        @chmod($sourcesPath, 0644);
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
                @file_put_contents($list, $mutated);
                logmsg('[INFO] Disabled stale buster entry in '.basename($list));
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
 * Parse CLI arguments.
 */
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
    if (preg_match('/^release[\/:]$/i', $spec) === 1) {
        return 'release';
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
        if ($rest === ':' || $rest === '/') {
            return 'release';
        }
        return $rest === '' ? 'release' : 'release:'.$rest;
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
        runFatal($cmd, EXIT_FETCH);
        runFatal('tar -xzf '.escapeshellarg($tar).' -C '.escapeshellarg($tmp).' --strip-components=1', EXIT_FETCH);
        return;
    }

    $clone = sprintf(
        'git clone --quiet --depth=1 --branch %s %s %s',
        escapeshellarg($spec['branch']),
        escapeshellarg($spec['repo']),
        escapeshellarg($tmp)
    );
    runFatal($clone, EXIT_FETCH);

    if ($spec['pin'] !== '') {
        $rev = escapeshellarg($spec['branch'].'@{'.$spec['pin'].'}');
        runFatal('cd '.escapeshellarg($tmp).' && git fetch --quiet && git checkout '.$rev, EXIT_FETCH);
    }
}

function runFatal(string $command, int $code): void
{
    logmsg('[RUN] '.$command);
    passthru($command, $rc);
    if ($rc !== 0) {
        fatal("Command failed (rc={$rc}): {$command}", $code);
    }
}

function runSoft(string $command): void
{
    logmsg('[RUN] '.$command);
    passthru($command, $rc);
    if ($rc !== 0) {
        logmsg("[WARN] Command failed (rc={$rc}): {$command}");
        logEvent('command_warn', ['command' => $command, 'rc' => $rc]);
    }
}

/**
 * Best-effort restore of PMSS root cron after updates that temporarily disable it.
 *
 * During phase 1 we remove `/etc/cron.d/pmss` to avoid cron activity while the
 * tree is partially refreshed. Flows that end before phase 2 must restore the
 * cron template here; otherwise defer to update-step2.
 */
function restoreRootCronBestEffort(string $context): void
{
    $helper   = '/scripts/util/setupRootCron.php';
    $template = '/etc/seedbox/config/root.cron';
    $target   = '/etc/cron.d/pmss';

    if (!file_exists($helper)) {
        logmsg('[WARN] setupRootCron.php missing; cannot restore root cron after '.$context);
        return;
    }
    if (!file_exists($template)) {
        logmsg('[WARN] root.cron template missing; cannot restore root cron after '.$context);
        return;
    }
    if (file_exists($target)) {
        logmsg('[INFO] Root cron already present; no restore needed after '.$context);
        return;
    }

    logmsg('[INFO] Restoring root cron after '.$context);
    runSoft($helper);
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
    runSoft(escapeshellarg(PHP_BINARY).' '.escapeshellarg($helper));
}

function ensureSnapshot(string $tmp): void
{
    $required = [
        $tmp.'/scripts',
        $tmp.'/scripts/update.php',
        $tmp.'/scripts/util/update-step2.php',
    ];
    foreach ($required as $path) {
        if (!file_exists($path)) {
            fatal("Snapshot missing required file: {$path}", EXIT_COPY);
        }
    }
}

function directoryHasContent(string $path): bool
{
    if (!is_dir($path)) {
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
    if (!directoryHasContent($scriptsSource)) {
        logmsg("[WARN] Snapshot scripts tree missing or empty, skipping copy");
        logEvent('tree_skipped', ['tree' => 'scripts']);
    } elseif ($dryRun) {
        logmsg("[DRY RUN] Would atomically swap /scripts from {$scriptsSource}");
    } else {
        $scriptsStaging = '/scripts.pmss-staging-'.$runId;
        $scriptsBackup  = '/scripts.pmss-backup-'.$runId;
        runSoft('rm -rf '.escapeshellarg($scriptsStaging));
        runFatal(sprintf('cp -a %s/. %s', escapeshellarg($scriptsSource), escapeshellarg($scriptsStaging)), EXIT_COPY);

        if (file_exists('/scripts') && !is_dir('/scripts')) {
            fatal('/scripts exists but is not a directory', EXIT_COPY);
        }
        if (is_dir('/scripts') && !@rename('/scripts', $scriptsBackup)) {
            fatal('Failed to rename /scripts to backup for atomic swap', EXIT_COPY);
        }
        if (!@rename($scriptsStaging, '/scripts')) {
            fatal('Failed to rename staged /scripts into place', EXIT_COPY);
        }
        runSoft('rm -rf '.escapeshellarg($scriptsBackup));
    }

    // Stage /etc tree with atomic swap for /etc/seedbox and overlay for the rest.
    $etcSource = $tmp.'/etc';
    if (!directoryHasContent($etcSource)) {
        logmsg("[WARN] Snapshot etc tree missing or empty, skipping copy");
        logEvent('tree_skipped', ['tree' => 'etc']);
    } elseif ($dryRun) {
        logmsg("[DRY RUN] Would atomically swap /etc/seedbox and overlay other /etc files from {$etcSource}");
    } else {
        if (is_dir($etcSource.'/skel') && is_dir('/etc/skel')) {
            runFatal('rm -rf /etc/skel/* /etc/skel/.[!.]* /etc/skel/..?*', EXIT_COPY);
        }

        // Atomic swap for /etc/seedbox
        $seedboxSource  = $etcSource.'/seedbox';
        $seedboxStaging = '/etc/seedbox.pmss-staging-'.$runId;
        $seedboxBackup  = '/etc/seedbox.pmss-backup-'.$runId;
        $haveSeedboxSnapshot = directoryHasContent($seedboxSource);
        if ($haveSeedboxSnapshot || is_dir('/etc/seedbox')) {
            runSoft('rm -rf '.escapeshellarg($seedboxStaging));
            if (is_dir('/etc/seedbox')) {
                runFatal(sprintf('cp -a %s %s', escapeshellarg('/etc/seedbox'), escapeshellarg($seedboxStaging)), EXIT_COPY);
            } else {
                @mkdir($seedboxStaging, 0755, true);
            }
            if ($haveSeedboxSnapshot) {
                runFatal(sprintf('cp -a %s/. %s', escapeshellarg($seedboxSource), escapeshellarg($seedboxStaging)), EXIT_COPY);
            }
            if (is_dir('/etc/seedbox') && !@rename('/etc/seedbox', $seedboxBackup)) {
                fatal('Failed to rename /etc/seedbox to backup for atomic swap', EXIT_COPY);
            }
            if (!@rename($seedboxStaging, '/etc/seedbox')) {
                fatal('Failed to rename staged /etc/seedbox into place', EXIT_COPY);
            }
            runSoft('rm -rf '.escapeshellarg($seedboxBackup));
        }

        // Overlay remaining /etc content (excluding seedbox) to preserve local edits.
        $entries = scandir($etcSource);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'seedbox') {
                    continue;
                }
                $path = $etcSource.'/'.$entry;
                runFatal('cp -rpu '.escapeshellarg($path).' /etc', EXIT_COPY);
            }
        }
    }

    // Stage /var as before.
    $varSource = $tmp.'/var';
    if (!directoryHasContent($varSource)) {
        logmsg("[WARN] Snapshot var tree missing or empty, skipping copy");
        logEvent('tree_skipped', ['tree' => 'var']);
    } elseif ($dryRun) {
        logmsg("[DRY RUN] Would copy var from {$varSource}");
    } else {
        runFatal('cp -a '.escapeshellarg($varSource).' /', EXIT_COPY);
    }

    if ($dryRun) {
        return;
    }

    runFatal('chmod -R o-rwx /scripts /root /etc/skel /etc/seedbox', EXIT_COPY);
    runFatal('find /scripts -type f -name "*.php" -exec chmod 0750 {} +', EXIT_COPY);
    runFatal('chmod 0750 /scripts/update.php', EXIT_COPY);
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
    runSoft(sprintf('cp -a %s/. %s', escapeshellarg($nested), escapeshellarg('/scripts')));
    runSoft('rm -rf '.escapeshellarg($nested));
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

    if (!is_dir(VERSION_DIR)) {
        @mkdir(VERSION_DIR, 0755, true);
    }

    $timestamp = time();
    $line      = $spec.'@'.date('Y-m-d H:i', $timestamp);
    $details['recorded_spec'] = $spec;
    $details['timestamp']     = date('c', $timestamp);

    @file_put_contents(VERSION_FILE, $line.PHP_EOL);
    @file_put_contents(VERSION_META, json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    // Backwards-compatibility: some tooling expects /etc/seedbox/runtime/version.
    // #TODO(Q4/2027): remove this compatibility write once all consumers use VERSION_FILE.
    if (!is_dir(dirname(VERSION_RUNTIME_FILE))) {
        @mkdir(dirname(VERSION_RUNTIME_FILE), 0755, true);
    }
    @file_put_contents(VERSION_RUNTIME_FILE, $line.PHP_EOL);

    // #TODO Ensure consistent permissions (0640) on version metadata and any
    //       future config artifacts written here.
}

function cleanup(string $path): void
{
    if ($path !== '' && is_dir($path)) {
        runSoft('rm -rf '.escapeshellarg($path));
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

    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__);
    foreach ($args as $arg) {
        $command .= ' '.escapeshellarg($arg);
    }

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
    $start = microtime(true);
    passthru(PHP_BINARY.' /scripts/util/update-step2.php', $rc);
    $duration = round(microtime(true) - $start, 3);

    // Interpret non-zero exit codes, including 128+signal (e.g. SIGKILL → 137).
    $status  = $rc === 0 ? 'ok' : 'error';
    $details = ['status' => $status, 'rc' => $rc, 'duration' => $duration];
    if ($rc !== 0 && $rc >= 128 && $rc <= 255) {
        $signal = $rc - 128;
        $name   = '';
        if ($signal === 9) {
            $name = 'SIGKILL';
        } elseif ($signal === 15) {
            $name = 'SIGTERM';
        }
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

    // When phase 2 will run, defer root cron restoration to update-step2 so
    // cron does not run mid-update. Otherwise restore immediately.
    if ($deferRootCronRestore) {
        logmsg('[INFO] Deferring root cron restore until update-step2');
    } else {
        restoreRootCronBestEffort('dist-upgrade');
    }

    // Best-effort refresh of MOTD after a successful dist-upgrade run. This
    // gives operators immediate visibility without requiring a reboot.
    $motd = '/scripts/util/motdGenerate.php';
    if (file_exists($motd)) {
        runSoft($motd);
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

    // Disable root cronjobs for update; update-step2 will reapply the template.
    $rootCron = '/etc/cron.d/pmss';
    if (file_exists($rootCron)) {
        @unlink($rootCron);
        logmsg('[INFO] Disabled /etc/cron.d/pmss during update; template will be reapplied in update-step2');
    }

    // Remove /etc/cron.d/updateQuotas if present: PMSS cron entries must be in /etc/cron.d/pmss
    // (deployed from /etc/seedbox/config/root.cron). This is a safety guard to prevent any
    // recurrence of the 2025-12-08 /home wipe class when cron runs during partial-update windows.
    // See incident report: docs/incidents/2025-12-08-home-wipe-updateQuotas-listUsers.md
    $obsoleteQuotaCron = '/etc/cron.d/updateQuotas';
    if (file_exists($obsoleteQuotaCron)) {
        @unlink($obsoleteQuotaCron);
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
        }
        if (!$options['dry_run'] && file_exists('/scripts/util/ftpConfig.php')) {
            logmsg('[INFO] Refreshing FTP configuration for --scripts-only run');
            runSoft(escapeshellarg(PHP_BINARY).' /scripts/util/ftpConfig.php');
        }
        if (!$options['dry_run']) {
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
