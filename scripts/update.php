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
 * | `--dist-upgrade=<max>` | Run `scripts/util/update-dist-upgrade.php` to perform a one-step Debian release upgrade capped at the requested maximum, then exit. |
 * | `--skip-self-update` | Internal flag injected during self-refresh to avoid recursion; operators should not pass it manually. |
 * | `--help`           | Print usage examples and exit without making changes. |
 */

declare(strict_types=1);

// Allow more headroom during large updates but still cap to avoid host-wide OOM.
@ini_set('memory_limit', '4096M');

const DEFAULT_REPO          = 'https://github.com/MagnaCapax/PMSS';
const CURL_UA               = 'PMSS-Updater (+https://pulsedmedia.com)';
const VERSION_DIR           = '/etc/seedbox/config';
const VERSION_FILE          = VERSION_DIR.'/version';
const VERSION_META          = VERSION_DIR.'/version.meta';
const JSON_LOG              = '/var/log/pmss-update.jsonl';
const SELF_UPDATE_SKIP_FLAG = '--skip-self-update';
const SCRIPTS_ONLY_FLAG     = '--scripts-only';

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

function logEvent(string $event, array $payload = []): void
{
    $payload['event'] = $event;
    $payload['ts']    = $payload['ts'] ?? date('c');

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
 * tree is partially refreshed. Some flows (dist-upgrade) exit before phase 2,
 * so we must restore the cron template here as well.
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

    // #TODO move /scripts and /etc/seedbox updates to staged + atomic swap (see docs/TODO.md)
    // so no process ever sees a partially refreshed tree.

    $trees = [
        'scripts' => function (string $source) {
            if (!is_dir('/scripts')) {
                @mkdir('/scripts', 0755, true);
            }
            if (!is_dir($source)) {
                fatal("Staged scripts source missing: {$source}", EXIT_COPY);
            }
            // Remove previous contents before copying in the new tree.
            runFatal('rm -rf /scripts/* /scripts/.[!.]* /scripts/..?*', EXIT_COPY);
            runFatal(sprintf('cp -a %s/. %s', escapeshellarg($source), escapeshellarg('/scripts')), EXIT_COPY);
        },
        'etc' => function (string $source) {
            if (is_dir($source)) {
                // Only wipe skel when the snapshot provides one and the target exists.
                if (is_dir($source.'/skel') && is_dir('/etc/skel')) {
                    runFatal('rm -rf /etc/skel/* /etc/skel/.[!.]* /etc/skel/..?*', EXIT_COPY);
                }
            }
            // Intentionally using cp -rpu here so locally-newer files under /etc
            // are not overwritten by the snapshot. This is desired behavior to
            // preserve operator edits or newer timestamps.
            runFatal('cp -rpu '.escapeshellarg($source).' /', EXIT_COPY);
        },
        'var' => function (string $source) {
            runFatal('cp -a '.escapeshellarg($source).' /', EXIT_COPY);
        },
    ];

    foreach ($trees as $name => $handler) {
        $source = $tmp.'/'.$name;
        if (!directoryHasContent($source)) {
            logmsg("[WARN] Snapshot {$name} tree missing or empty, skipping copy");
            logEvent('tree_skipped', ['tree' => $name]);
            continue;
        }
        if ($dryRun) {
            logmsg("[DRY RUN] Would copy {$name} from {$source}");
            continue;
        }
        $handler($source);
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

    logmsg('Handing off to update-step2.php');
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

function runAutoremove(): void
{
    // Guardrail: this helper performs package removals via apt-get and must
    // NEVER be invoked from `--scripts-only` flows. Scripts-only runs are
    // explicitly defined to avoid any package manager side effects.
    $cmd = 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none '
        .'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold '
        .'autoremove -y';
    runFatal($cmd, EXIT_COPY);
}

/**
 * @param bool|string $distUpgrade
 */
function maybeRunDistUpgrade($distUpgrade): void
{
    if ($distUpgrade === false) {
        return;
    }
    if ($distUpgrade === true) {
        fatal("You must specify a maximum version for dist-upgrade (e.g. --dist-upgrade=11 or --dist-upgrade=bullseye).", EXIT_PARSE);
    }
    logEvent('dist_upgrade_start', ['target' => $distUpgrade]);
    $cmd = '/scripts/util/update-dist-upgrade.php ' . escapeshellarg($distUpgrade);
    logmsg('[RUN] '.$cmd);
    passthru($cmd, $rc);
    logEvent('dist_upgrade_end', ['rc' => $rc]);

    // Dist-upgrade flow exits before phase 2; ensure root cron is restored even
    // though phase 1 disabled it to avoid partial-update windows.
    restoreRootCronBestEffort('dist-upgrade');

    if ($rc !== 0) {
        fatal('dist-upgrade helper exited with status '.$rc, EXIT_DIST);
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

    $startTime    = microtime(true);
    $originalHash = currentUpdaterHash();

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

    // Remove legacy updateQuotas cron to prevent recurrence of the 2025-12-08 /home wipe.
    // See incident report: docs/incidents/2025-12-08-home-wipe-updateQuotas-listUsers.md
    // #TODO remove this unlink around 2030-12 once the fleet is fully refreshed.
    $legacyQuotaCron = '/etc/cron.d/updateQuotas';
    if (file_exists($legacyQuotaCron)) {
        @unlink($legacyQuotaCron);
        logmsg('[INFO] Removed legacy updateQuotas cron entry to prevent quota-refresh regression');
    }

    $distUpgradeHelper = '/scripts/util/update-dist-upgrade.php';
    if ($options['dist_upgrade'] && file_exists($distUpgradeHelper)) {
        maybeRunDistUpgrade($options['dist_upgrade']);
        return;
    } elseif ($options['dist_upgrade']) {
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
        maybeRunDistUpgrade($options['dist_upgrade']);
        return;
    }

    if ($options['scripts_only']) {
        // Scripts-only runs refresh /scripts and /etc trees but intentionally
        // skip the full phase 2 orchestrator. We still need to converge
        // skeleton and configuration permissions so services like rTorrent
        // continue to see readable config under /etc/seedbox after the
        // initial hardening in stageSnapshot().
        if (!$options['dry_run'] && file_exists('/scripts/util/setupSkelPermissions.php')) {
            logmsg('[INFO] Refreshing skeleton/config permissions for --scripts-only run');
            runSoft(escapeshellarg(PHP_BINARY).' /scripts/util/setupSkelPermissions.php');
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
